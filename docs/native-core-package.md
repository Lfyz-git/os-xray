# Native OPNsense package: os-xray 3.3.0_1 (v3.3.0)

The standard native build is provided under `packaging/native/` and uses
`opnsense/plugins/Mk/plugins.mk` to generate a genuine FreeBSD `.pkg`,
including OPNsense plugin metadata, generated package hooks and dependencies.

## Packages

- **os-xray 3.3.0_1**: MVC/GUI, service actions, boot/cron integration, config
  generator and subscription manager. No bundled Xray or tun2socks executable.
- **xray-core**: regular OPNsense package (`security/xray-core`). The plugin
  uses `/usr/local/bin/xray`; its version is independent of os-xray.
- **xray-tun2socks 2.7.0**: separately packaged FreeBSD amd64 upstream binary,
  downloaded with pinned SHA-256 and installed under
  `/usr/local/libexec/os-xray/tun2socks`, never on top of an official package.
- **php85-curl**: normal OPNsense package needed by HTTPS subscriptions.

The native plugin depends on the three other packages. The **signed private stable and test
repositories** carry `os-xray` and `xray-tun2socks`; the official OPNsense
repository provides `xray-core` and `php85-curl`.

## Build

The `Build native OPNsense packages` GitHub Actions workflow uses an isolated
FreeBSD 15.1 amd64 VM and the upstream OPNsense plugin build system.
On a FreeBSD build host, with `opnsense/plugins` checked out at
`./opnsense-plugins` and build dependencies installed:

```sh
clear
sh packaging/native/build.sh
```

Output in `dist/`: `os-xray-3.3.0_1.pkg` and
`xray-tun2socks-2.7.0.pkg`. No installation on a running OPNsense system
takes place during CI. The generated plugin package includes normal OPNsense
post-install integration hooks, without running the old installer or Apply.

## Scope and installation policy

- **Clean installation only**, with `pkg` and matching OPNsense repositories.
- Only **pkg-to-pkg upgrades** will be supported. No scripts, tests, adapters
  or compatibility paths for `install.sh` or `upgrade.sh`.
- The old v3.2.1 release is independent and remains script-installed; its
  pinned binaries are not modified by this branch.
- Initial acceptance testing was done on **OPNsense-Lab**, not Styx.
- The signed **stable** repository is published at
  `https://lfyz-git.github.io/os-xray/FreeBSD:15:amd64/26.7/stable/repo`.
  A separate `/test/repo` channel remains for OPNsense-Lab; both are built and
  signed from the same new artifacts. Install only one channel per firewall.
  Install and upgrade via **System → Firmware → Plugins / Updates**.
- OPNsense-Lab validated signed catalogue trust, clean GUI installation of
  `3.3.0`, the stock OPNsense `xray-core` dependency, native GUI update to
  `3.3.0_1`, successful subscription refresh, Xray/tun2socks startup and
  IPv4 client egress through the CZ (`85.137.165.251`) and KG
  (`91.213.233.53`) Kalamar exits using LeastLoad. The Lab client routed via
  OPNsense-Lab gateway `10.105.0.2`.
- Production migration from `install.sh` is intentionally **not** supported.
  An existing legacy device requires a separate, manually planned clean
  installation with backup and reconfiguration, not an in-place pkg upgrade.

## Updating the core from the GUI

Advanced shows installed and repository versions and offers explicit checks
and Xray-core upgrades through `pkg`. On confirmation, the updater records
which managed tunnels are running, stops those tunnels, upgrades only
`xray-core`, validates the applied JSON with the new binary and restarts only
previously running tunnels. Expect a brief interruption. An unmanaged process
blocks the update. Failures leave instances stopped for manual diagnosis.

The independent **Xray-core upgrade path**, system reboot recovery and
package removal have not all been exercised in the final Lab scenario.
Do not treat the successfully tested native plugin package upgrade as proof
that independent core upgrades are validated.
There is no automatic core rollback or unattended upgrade.
