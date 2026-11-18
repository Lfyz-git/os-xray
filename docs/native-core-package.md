# Native Xray-core package management (draft)

This is work toward the native `os-xray` package; it is **not** part of the
script-installed v3.2.1 release. Do not deploy this branch on a running firewall.

## Why

The legacy installer pins Xray-core 26.3.27 and downloads the
`Xray-freebsd-64.zip` GitHub Release archive to
`/usr/local/bin/xray-core`. That is intentionally reproducible for the
existing v3.2.1 release but unsuitable for native package updates.

For OPNsense 26.7, `opnsense/tools/config/26.7/ports.conf` includes
`security/xray-core` in the distribution ports set. The FreeBSD port
installs its binary at `/usr/local/bin/xray`. The current presence of an
installable package should be verified on OPNsense-Lab before release:

```sh
clear
pkg rquery -r OPNsense '%n %v %R' xray-core
pkg info xray-core
```

The native os-xray package must depend on the OPNsense-managed
`xray-core` package; it must **not** bundle or overwrite its executable.
Core releases and os-xray plugin releases are independent. `net/tun2socks`
is currently not included in the OPNsense 26.7 ports build list and needs
an independently managed package/source decision.

## Core updates from the plugin GUI

The Advanced tab can query the installed and repository versions, refresh the
official OPNsense catalog on request, and explicitly upgrade only `xray-core`
through `pkg upgrade -r OPNsense xray-core`.

Safeguards: the update action requires the core to be owned by `pkg`,
declines to run while Xray or tun2socks processes are active, serializes
updates, validates all applied configs before and after changing binaries,
and never performs automatic Apply, Start, Stop, or Restart. If validation
fails after an upgrade, the user must restore a known-good package/version
before starting services. Automatic rollback is **not** implemented.

The backend must be exercised on OPNsense-Lab with actual pkg metadata and
a noncritical instance before this feature is merged or used on Styx.

## Out of scope

- Any migration from `install.sh` / `upgrade.sh` to `pkg`.
- Historical configuration schema migrations or support for script-installed cores.
- An embedded downloader for arbitrary upstream GitHub Release binaries.
- Automatic core updates without an explicit administrative action.
