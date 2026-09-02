import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
HELPER = (
    ROOT
    / "net/os-trusttunnel/src/opnsense/scripts/trusttunnel/cert_helpers.php"
)


def run_php(body):
    completed = subprocess.run(
        [
            "php",
            "-r",
            "require " + json.dumps(str(HELPER)) + "; " + body,
        ],
        check=True,
        capture_output=True,
        text=True,
    )
    return completed.stdout


class MaterializeCertsTest(unittest.TestCase):
    def test_selected_caref_is_appended_to_leaf(self):
        output = run_php(
            r'''
            $leaf = "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n";
            $issuer = "-----BEGIN CERTIFICATE-----\nISSUER\n-----END CERTIFICATE-----\n";
            $config = ['ca' => [[
                'refid' => 'issuer-ref',
                'crt' => base64_encode($issuer),
            ]]];
            $cert = ['crt' => base64_encode($leaf), 'caref' => 'issuer-ref'];
            echo build_certificate_chain($config, $cert, 'leaf-ref');
            '''
        )
        self.assertEqual(output.count("-----BEGIN CERTIFICATE-----"), 2)
        self.assertLess(output.index("LEAF"), output.index("ISSUER"))

    def test_missing_caref_fails_loud(self):
        output = run_php(
            r'''
            $leaf = "-----BEGIN CERTIFICATE-----\nLEAF\n-----END CERTIFICATE-----\n";
            try {
                build_certificate_chain(
                    ['ca' => []],
                    ['crt' => base64_encode($leaf), 'caref' => 'missing'],
                    'leaf-ref'
                );
                echo 'unexpected-success';
            } catch (UnexpectedValueException $e) {
                echo $e->getMessage();
            }
            '''
        )
        self.assertIn("CA refid=missing not found", output)
        self.assertNotIn("unexpected-success", output)

    def test_nested_caref_chain_is_complete_and_ordered(self):
        output = run_php(
            r'''
            function pem($name) {
                return "-----BEGIN CERTIFICATE-----\n" . $name
                    . "\n-----END CERTIFICATE-----\n";
            }
            $config = ['ca' => [
                ['refid' => 'intermediate', 'crt' => base64_encode(pem('INTERMEDIATE')), 'caref' => 'root'],
                ['refid' => 'root', 'crt' => base64_encode(pem('ROOT'))],
            ]];
            $cert = ['crt' => base64_encode(pem('LEAF')), 'caref' => 'intermediate'];
            echo build_certificate_chain($config, $cert, 'leaf-ref');
            '''
        )
        self.assertEqual(output.count("-----BEGIN CERTIFICATE-----"), 3)
        self.assertLess(output.index("LEAF"), output.index("INTERMEDIATE"))
        self.assertLess(output.index("INTERMEDIATE"), output.index("ROOT"))

    def test_cyclic_caref_fails_loud(self):
        output = run_php(
            r'''
            $pem = "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----\n";
            $config = ['ca' => [
                ['refid' => 'a', 'crt' => base64_encode($pem), 'caref' => 'b'],
                ['refid' => 'b', 'crt' => base64_encode($pem), 'caref' => 'a'],
            ]];
            try {
                build_certificate_chain(
                    $config,
                    ['crt' => base64_encode($pem), 'caref' => 'a'],
                    'leaf-ref'
                );
                echo 'unexpected-success';
            } catch (UnexpectedValueException $e) {
                echo $e->getMessage();
            }
            '''
        )
        self.assertIn("CA caref cycle", output)
        self.assertNotIn("unexpected-success", output)


if __name__ == "__main__":
    unittest.main()
