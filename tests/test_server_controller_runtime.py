import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
CONTROLLER = (
    ROOT
    / "net/os-trusttunnel/src/opnsense/mvc/app/controllers/OPNsense"
    / "TrustTunnel/Api/ServerController.php"
)


def run_reconfigure(output):
    script = r'''
namespace OPNsense\Base {
    class ApiMutableModelControllerBase {
        protected $request;
        public function __construct() {
            $this->request = new class {
                public function isPost() { return true; }
            };
        }
    }
}
namespace OPNsense\Core {
    class Backend {
        public function configdRun($action) { return $GLOBALS['backend_output']; }
    }
    class Config {}
}
namespace ContractTest {
    require $GLOBALS['controller_path'];
    echo json_encode((new \OPNsense\TrustTunnel\Api\ServerController())->reconfigureAction());
}
'''
    bootstrap = (
        "namespace { $GLOBALS['backend_output'] = "
        + json.dumps(output)
        + "; $GLOBALS['controller_path'] = "
        + json.dumps(str(CONTROLLER))
        + "; } "
    )
    completed = subprocess.run(
        ["php", "-r", bootstrap + script],
        check=True,
        capture_output=True,
        text=True,
    )
    return json.loads(completed.stdout)


class ServerControllerRuntimeTest(unittest.TestCase):
    def test_explicit_ok_marker_succeeds(self):
        self.assertEqual(run_reconfigure("OK\n")["status"], "ok")

    def test_empty_output_fails(self):
        self.assertEqual(run_reconfigure("")["status"], "failed")

    def test_error_output_fails(self):
        self.assertEqual(run_reconfigure("render failed")["status"], "failed")


if __name__ == "__main__":
    unittest.main()
