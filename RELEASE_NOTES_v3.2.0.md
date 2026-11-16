# os-xray v3.2.0 — release notes

Independent release of [Lfyz-git/os-xray](https://github.com/Lfyz-git/os-xray), based on the OPNsense 26.7 / FreeBSD 15.1 plugin.

## New in v3.2.0

- **Global subscriptions:** import HTTPS-hosted or pasted VLESS share links, base64-encoded lists and Xray JSON outbound collections. Node names are preserved in selectors.
- **Automatic refresh:** cron checks every 15 minutes; per-source interval defaults to 360 minutes. Failed downloads, invalid candidates and disappearing selected nodes retain the previous source cache.
- **Manual:** one selected proxy outbound in the applied Xray config, plus `direct` for local-network bypass.
- **LeastLoad:** pool and fallback selected from independent subscriptions, with Burst Observatory probes and minimal runtime outbounds.
- **Sniffing and editable Routing:** ordered protocol/domain/IP/port conditions with Proxy, Direct and Block actions. One-click BitTorrent → Direct preset.
- **Advanced:** read-only display of the *applied* Xray JSON, including process status and copy controls. Note: JSON contains connection credentials.
- **OPNsense timezone:** subscription refresh timestamps are stored in UTC and displayed in the timezone configured on the firewall.
- **Lifecycle safety:** candidate validation before stopping a running instance, unchanged Apply without restart, rollback on failure, watchdog recovery and resource-conflict preflight.

## Compatibility

- Tested target: **OPNsense 26.7.x / FreeBSD 15.1 amd64**.
- Plugin version **3.2.0**; Xray-core **26.3.27** and tun2socks **2.7.0** remain pinned by the installer.
- Existing v3.x installations: use the **tagged `upgrade.sh`**, not `install.sh` or an unpinned development checkout. `upgrade.sh` backs up `config.xml` and the plugin files, publishes plugin-owned files, restarts configd, and regenerates cron without issuing Apply or intentionally restarting tunnel processes.
- Before touching an operational router: make a VM/config backup, retain independent console access and follow the upgrade precautions in README. An explicit first Apply after an upgrade may restart the tunnel.

## Verification and scope

- PR #2: Linux generator/subscription smoke checks and selected lifecycle regression checks.
- FreeBSD 15.1 CI on `develop`: SUCCESS after merging PR #2.
- OPNsense-Lab: manual and LeastLoad routing generation, real egress, cron registration, subscription refresh, no restart on unchanged source, and timezone presentation.
- Actual BitTorrent sessions were not generated; protocol classification relies on Xray-core.
- No deployment to the production Styx router is claimed.

## Archive integrity

The release provides `opnsense-xray-v3.2.0.tar.gz` together with `SHA256SUMS`. Verify the downloaded archive against its checksum before installation.

## Links

- Feature PR: https://github.com/Lfyz-git/os-xray/pull/2
- Upstream lifecycle contribution (still separate): https://github.com/SpyLive/os-xray/pull/7
