# PasarGuard HWID support (os-xray v3.2.1)

The OPNsense Xray plugin identifies its **subscription downloader**, not the individual devices behind the firewall.

## Request identity

Every HTTPS subscription is associated with a stable `X-HWID`, generated as 16 cryptographically random bytes (32 lowercase hex characters) when left blank. Existing HWIDs may also be entered manually. The same value is sent on manual Refresh and cron updates. **Do not use MAC addresses or hardware serial numbers.** Multiple subscriptions each receive their own HWID.

On each subscription download, the plugin sends:

```http
User-Agent: os-xray/3.2.1
X-HWID: <saved per-subscription device ID>
X-Device-OS: OPNsense
X-Ver-OS: <OPNsense core version, when available>
X-Device-Model: OPNsense Firewall
Accept: text/plain, application/json
```

## Provider configuration

PasarGuard identifies a user by the token in the subscription URL, registers the HWID/device metadata, and chooses the output format from its **first matching User-Agent rule**. Its source implements matching with a regex. Add a subscription rule for `^os-xray/.*` and select **links** (plain VLESS lines), **links_base64** (base64 VLESS lines), or **xray** (Xray JSON outbounds); all three can be parsed by this plugin.

If there is no matching rule, PasarGuard may return HTTP **406 Client not supported**. Configure the rule *before* testing the plugin. An HTTP 403 can indicate a device limit or provider-side restriction; do not work around this by generating a new HWID on each request.

## Migration and safety

- Existing HTTPS sources without an HWID receive one the first time they are saved/refreshed; the identifier is written to `config.xml` before any manual network request, and retained even if automatic refresh fails.
- A manually entered, previously registered HWID is preserved.
- HWIDs are not exposed in subscription listing or node-list API responses. The authorized edit form shows the stored value.
- HTTP errors never include the full token-bearing subscription URL, response body or cURL diagnostic string.
- Download requires the OPNsense-provided **PHP cURL extension**; no additional package is needed on a standard OPNsense 26.7 installation.
- HTTPS certificate and hostname checks are enforced, redirects and proxies are disabled, all DNS answers must be public, and cURL connects to a validated **pinned IP** to prevent DNS rebinding. Maximum body size is 1 MiB, with a 12-second total timeout.
- This is a new plugin feature; upgrading **Styx** is a separate operation requiring backup and explicit rollout planning.
