# Native OPNsense package: os-xray 3.3.0 (pre-release)

The standard native build is provided under `packaging/native/` and uses
`opnsense/plugins/Mk/plugins.mk` to generate a genuine FreeBSD `.pkg`,
including OPNsense plugin metadata, generated package hooks and dependencies.

## Packages

- **os-xray 3.3.0**: MVC/GUI, service actions, boot/cron integration, config
  generator and subscription manager. No bundled Xray or tun2socks executable.
- **xray-core**: regular OPNsense package (`security/xray-core`). The plugin
  uses `/usr/local/bin/xray`; its version is independent of os-xray.
- **xray-tun2socks 2.7.0**: separately packaged FreeBSD amd64 upstream binary,
  downloaded with pinned SHA-256 and installed under
  `/usr/local/libexec/os-xray/tun2socks`, never on top of an official package.
- **php85-curl**: normal OPNsense package needed by HTTPS subscriptions.

The native plugin depends on the three other packages. A future private
repository will carry `os-xray` and `xray-tun2socks`; the official OPNsense
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

Output in `dist/`: `os-xray-3.3.0.pkg` and
`xray-tun2socks-2.7.0.pkg`. No installation on a running OPNsense system
takes place during CI. The generated plugin package includes normal OPNsense
post-install integration hooks, without running the old installer or Apply.

## Scope and installation policy

- **Clean installation only**, with `pkg` and matching OPNsense repositories.
- Only **pkg-to-pkg upgrades** will be supported. No scripts, tests, adapters
  or compatibility paths for `install.sh` or `upgrade.sh`.
- The old v3.2.1 release is independent and remains script-installed; its
  pinned binaries are not modified by this branch.
- The first device test must use a **clean OPNsense-Lab**, not Styx.
- A signed private package repository and automatic firmware-GUI update path
  are separate publication steps; these CI artifacts are *not yet a repo*.

## Updating the core from the GUI

Advanced shows installed and repository versions and offers explicit checks
and Xray-core upgrades through `pkg`. On confirmation, the updater records
which managed tunnels are running, stops those tunnels, upgrades only
`xray-core`, validates the applied JSON with the new binary and restarts only
previously running tunnels. Expect a brief interruption. An unmanaged process
blocks the update. Failures leave instances stopped for manual diagnosis.

The update orchestration, cross-process locks and error handling still need
functional verification on OPNsense-Lab after the native package build.
There is no automatic core rollback or unattended upgrade.
