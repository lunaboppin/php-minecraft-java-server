# Account authentication

`onlineMode: true` implements the pinned Java server's empty server ID, 1024-bit RSA key exchange with a per-connection four-byte challenge, signed SHA-1 session digest, continuous AES/CFB8 encryption, session verification and GameProfile properties. The client-supplied UUID never grants an online identity. Replayed/invalid challenges and unavailable verification fail before configuration. No offline fallback is provided.

Verification uses asynchronous cURL HTTPS requests with certificate/hostname verification, bounded response bodies and timeouts. The verifier obtains the session endpoint from Minecraft's production discovery service, caches it for 24 hours, and exposes no configuration option for an alternate authentication endpoint. The [official discovery response](https://discovery.minecraftservices.com/minecraft/client) currently identifies the Mojang `hasJoined` endpoint. Both OpenSSL and cURL with asynchronous DNS are required for online mode; the Docker runtime and panel installer include them.

New example and installer configurations enable online mode. To preserve existing server behavior, configurations that omit `onlineMode` retain offline mode. Setting it explicitly is recommended. Offline identities remain unverified. Authenticated profiles use `online:<hex UUID>` records; old offline player records keep their original keys. Switching modes preserves world edits and both sets of player records, but does not transfer inventories. No automatic identity migration is attempted.

## Evidence

- `tools/ExportAuthentication.java` uses the checksum-pinned official JAR's `Crypt`, encryption-request and key-response codecs to export RSA/AES/hash fixtures. The bundled private key is **test-only**; every production process generates a fresh key with OpenSSL's CSPRNG.
- `tests/authentication.php` checks official codec bytes, the server digest, fragmented continuous cipher streams, malformed/wrong challenges, authenticated profile parsing, already-buffered ciphertext and independent persisted identities.
- `tests/authentication-network.php` uses an isolated fixture verifier to exercise encrypted login/configuration, profile properties, operator commands, duplicate-session replacement and restart persistence, invalid/replayed challenges and unavailable verification. The production entrypoint cannot enable this fixture service.
- `tools/parity/authentication.py` connects an unjoined synthetic session to both PHP and the pinned Java server. It checks encrypted rejection and retains decrypted packet bodies for official codec validation. It requires an existing EULA acknowledgement and uses isolated worlds. It makes no account login request or acceptance on your behalf.

## Remaining acceptance

Issue #5 stays open until real verified-account logins and the complete lifecycle matrix are evidenced against Java 26.3. A successful fixture-verifier test does not prove a real Mojang account login. Signed player chat, chat-session keys, moderation/profile actions, secure-profile enforcement and transfer/cookie login are separate incomplete protocol work. Discovery refresh/retry timings and exact error wording are not yet established as identical to vanilla.
