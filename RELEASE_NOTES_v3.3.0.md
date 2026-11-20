# os-xray v3.3.0 — native OPNsense package

This release introduces installation and updates through the standard OPNsense **pkg/Firmware** interface instead of the historical shell installer.

## Native packages

- **os-xray 3.3.0_1** — GUI, subscriptions, independent TUN instances, routing and LeastLoad, lifecycle hooks, and package-managed core update controls.
- **xray-tun2socks 2.7.0** — separate checksum-verified private dependency.
- **xray-core** and **php85-curl** — dependencies resolved from the **official OPNsense** firmware repository, not bundled or pinned by os-xray.

Packages are distributed via the signed test repository:

`https://lfyz-git.github.io/os-xray/FreeBSD:15:amd64/26.7/test/repo`

[Install instructions and trust-key verification](https://github.com/Lfyz-git/os-xray/blob/main/packaging/repository/README.md)

## Fixes and interface

- Xray 26.10.21 compatibility: candidate configuration is checked as a private `config.json` and removed after validation.
- **General** opens first. The global **Enable Xray** switch controls all instances; when off, Instances shows a warning and prevents no-op Start actions.
- **General**, **Instances**, **Subscriptions**, **Diagnostics**, **Log** and **Advanced** are available in the OPNsense sidebar; installation and upgrades refresh the sidebar cache.
- Xray-core updates can be checked and deliberately applied in **Advanced**, with temporary tunnel interruption permitted and managed restart.

## Scope

- Target: **OPNsense 26.7 / FreeBSD 15 amd64**.
- Use a clean native install or an update from an earlier **pkg-installed** os-xray version.
- **No migration** from `install.sh` / `upgrade.sh` installations, and no promise of upstream or long-term community support.
- Do not replace official OPNsense repositories with FreeBSD ports or override system-owned core binaries.
- Test on **OPNsense-Lab** before using on any production firewall. In particular, verify subscriptions, live tunnel startup, routing and package upgrade behaviour.

This native release intentionally has **no legacy installer tarball**. The signed package repository is the distribution channel.
