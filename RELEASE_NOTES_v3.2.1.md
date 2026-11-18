# os-xray v3.2.1 — release notes

Independent maintenance release of [Lfyz-git/os-xray](https://github.com/Lfyz-git/os-xray), following v3.2.0.

## New in v3.2.1

- **Persistent subscription HWID:** a stable per-HTTPS-source hardware ID is generated and stored in OPNsense configuration. The same ID is sent on manual and scheduled refreshes, and can be set explicitly when needed.
- **PasarGuard identification:** subscription requests include `User-Agent: os-xray/3.2.1`, `X-HWID`, `X-Device-OS: OPNsense`, `X-Ver-OS` (from the OPNsense core product metadata JSON), and `X-Device-Model: OPNsense Firewall`.
- **PHP cURL download:** HTTPS-only requests with TLS verification, DNS resolution pinned to a validated public address, redirects disabled, download size and time limits, and sanitized errors.
- **Cron parity:** scheduled subscription refresh uses the same download path and identifying headers as manual refresh.

## PasarGuard configuration

Set a User-Agent mapping `^os-xray/.*` to a supported format such as `links` or `xray`. For HWID registration, enable HWID checks and ensure the subscription user does not have an explicit HWID limit of `0` (which disables device registration). PasarGuard records every refresh in subscription-client history but stores only one device for a stable HWID. Its current database implementation does not update the OS version of an already-registered HWID; registering the device afresh uses the current metadata.

## Compatibility and scope

- Target: **OPNsense 26.7.x / FreeBSD 15.1 amd64**.
- Plugin **v3.2.1**; standalone installer still pins **Xray-core 26.3.27** and **tun2socks 2.7.0**.
- For existing script-installed v3.x deployments, the tagged `upgrade.sh` is available. It preserves configuration/backups and does not intentionally issue Apply or restart a running tunnel. Make a VM/config backup and keep console access before upgrading a production router.
- HWID was verified on **OPNsense-Lab** against PasarGuard, including identity persistence and correct OS version after fresh registration; **Styx has not been modified**.
- The native `pkg` format and third-party repository are separate future work; neither is included in this release.

## Provenance

- HWID feature: https://github.com/Lfyz-git/os-xray/pull/4
- Previously released subscriptions, routing and LeastLoad: https://github.com/Lfyz-git/os-xray/releases/tag/v3.2.0

## Archive integrity

The release provides `opnsense-xray-v3.2.1.tar.gz` and `SHA256SUMS`. Check the SHA-256 checksum before use.
