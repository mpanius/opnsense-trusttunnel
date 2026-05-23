# FreeBSD ports for TrustTunnel

This directory holds two FreeBSD ports that produce the binaries consumed by the
`os-trusttunnel` plugin (which depends on them via `PLUGIN_DEPENDS=trusttunnel
trusttunnel-client`):

| Port | Upstream | Binary | Tag |
| --- | --- | --- | --- |
| `security/trusttunnel` | <https://github.com/TrustTunnel/TrustTunnel> | `trusttunnel_endpoint`, `trusttunnel_setup_wizard` | `v1.0.33` |
| `security/trusttunnel-client` | <https://github.com/TrustTunnel/TrustTunnelClient> | `trusttunnel_client` | `v1.1.4` |

`security/trusttunnel` is a pure Cargo workspace; `security/trusttunnel-client`
drives the upstream CMake + Conan + Rust hybrid build via its top-level Makefile.

See each port's `UPSTREAM-NOTES.md` for the decisions, BoringSSL pinning, and
patch list.

## Build environment (FreeBSD 14 VM on Proxmox)

Per `docs/plans/2026-05-23-opnsense-trusttunnel-plugin.md` Task 3 — Proxmox host
`root@[internal-ip]`, FreeBSD 14.x VM with:

- 4 vCPU, 8 GB RAM, 30 GB disk
- Hostname `freebsd-build`
- Bridged network with DHCP
- Packages: `git cargo rust llvm pkgconf cmake ninja perl5 python311 py311-pip`

### One-time prerequisites

```sh
# Inside the FreeBSD VM, as a non-root build user (e.g. `builder`):
git clone https://github.com/TrustTunnel/TrustTunnelClient.git ~/TrustTunnelClient
cd ~/TrustTunnelClient
# Export AdGuard Conan recipes into the local cache (required by trusttunnel-client port).
python3 -m venv .venv && . .venv/bin/activate
pip install conan
./scripts/bootstrap_conan_deps.py
```

This populates `~/.conan2/p/` with the AdGuard private recipes that the
`security/trusttunnel-client` port consumes. Done once per VM lifetime.

### Build the ports

```sh
git clone https://github.com/mpanius/opnsense-trusttunnel.git ~/opnsense-trusttunnel
cd ~/opnsense-trusttunnel/freebsd-port/security/trusttunnel
make makesum         # populates distinfo from the actual upstream tarball
make package         # → work/pkg/trusttunnel-1.0.33.pkg

cd ../trusttunnel-client
make makesum
make package         # → work/pkg/trusttunnel-client-1.1.4.pkg
```

### Build the plugin pkg

```sh
cd ~/opnsense-trusttunnel
make package         # → work/pkg/os-trusttunnel-0.1.0.pkg
```

## Local pkg-repo (release time — Task 12)

Once all three pkgs build cleanly, they get signed (ed25519 key generated
offline) and indexed via `pkg repo` for the self-hosted repo at
`[publish-host]:/var/www/trusttunnel-repo/`.

## Future: upstream into freebsd-ports tree

These port directories are written in standard FreeBSD ports format so they can
be PR'd into `github.com/freebsd/freebsd-ports` later. v1 ships from a private
overlay only.
