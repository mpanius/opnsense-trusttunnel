# Release runbook — os-trusttunnel v1

> Audience: maintainer (mpanius). Step-by-step from `make package` to a
> signed pkg sitting on a public-facing host.

## 0. Prereqs

- FreeBSD 14 build VM up (per `freebsd-port/README.md`)
- Both `security/trusttunnel` and `security/trusttunnel-client` ports
  build cleanly (`make package` exits 0)
- Plugin pkg builds cleanly (`make package` in repo root)

## 1. Generate the pkg-signing keypair (one-time, OFFLINE)

⛔ NEVER on the FreeBSD build VM. Use your laptop or a dedicated
air-gapped box. The private key must end up in 1Password.

```sh
# ed25519 (FreeBSD pkg supports ed25519 since pkg 1.18)
openssl genpkey -algorithm ED25519 -out trusttunnel-repo.key
openssl pkey    -in trusttunnel-repo.key -pubout -out repo-pub.cert
```

- Commit `repo-pub.cert` to this repo (the public key — safe to publish).
- Store `trusttunnel-repo.key` in 1Password under
  `opnsense-trusttunnel / pkg signing key`. NEVER commit it; `.gitignore`
  excludes `*.key`.

## 2. Sign and index the pkg directory

On the FreeBSD build VM (or wherever you collect the three .pkg files):

```sh
mkdir -p /tmp/trusttunnel-repo/FreeBSD:14:amd64/All
cp ~/opnsense-trusttunnel/freebsd-port/security/trusttunnel/work/pkg/*.pkg          /tmp/trusttunnel-repo/FreeBSD:14:amd64/All/
cp ~/opnsense-trusttunnel/freebsd-port/security/trusttunnel-client/work/pkg/*.pkg  /tmp/trusttunnel-repo/FreeBSD:14:amd64/All/
cp ~/opnsense-trusttunnel/work/pkg/os-trusttunnel-*.pkg                              /tmp/trusttunnel-repo/FreeBSD:14:amd64/All/

# Sign and index — pkg-repo asks for the private key path.
pkg repo /tmp/trusttunnel-repo/FreeBSD:14:amd64 signing_command:'openssl dgst -sha256 -sign /path/to/trusttunnel-repo.key -binary'
```

## 3. Publish to the chosen host

**Primary host:** `[publish-host]` (`[internal-publish-ip]`) — LE cert for `packages.example.com`
already present, nginx running. Fallback: `[fallback-host]` (NL).

```sh
# On [publish-host] (or [fallback-host] fallback):
mkdir -p /var/www/trusttunnel-repo
rsync -av /tmp/trusttunnel-repo/ root@[publish-host]:/var/www/trusttunnel-repo/

# nginx snippet (drop into a server block on packages.example.com or a subpath):
# location /pkgs/ { alias /var/www/trusttunnel-repo/; autoindex on; }
```

After publishing:

```sh
curl -sI https://packages.example.com/pkgs/FreeBSD:14:amd64/packagesite.txz | head -1
# Expect: HTTP/2 200
```

## 4. Substitute the URL in docs/install.md

`docs/install.md` ships with `${REPO_URL}` placeholders. Before tagging
the release, run:

```sh
sed -i '' 's,\${REPO_URL},https://packages.example.com/pkgs,g' docs/install.md
# Verify no placeholder survives — release-blocker check (Task 12 DoD):
! grep -q '\${REPO_URL}' docs/install.md && echo OK
```

## 5. Tag and push

```sh
git tag -s v0.1.0 -m "v0.1.0 — initial release"
git push origin master --tags
```

## 6. Smoke-test on a fresh OPNsense CE 25.x VM

Follow `docs/install.md` from scratch on a clean OPNsense VM:

1. Drop `repo-pub.cert` into `/usr/local/etc/ssl/trusttunnel-repo.cert`
2. Drop `trusttunnel.conf` (template in `docs/install.md`) into
   `/usr/local/etc/pkg/repos/`
3. `pkg update && pkg install -y os-trusttunnel`
4. Open https://&lt;OPNsense&gt;/ui/trusttunnel/ — verify TS-001 passes
