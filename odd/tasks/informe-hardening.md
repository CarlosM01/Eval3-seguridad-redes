# Security hardening from informe.pdf (eval1)

Objective: bring the `eval1` GNS3 lab in line with `docs/informe.pdf` (CI3062, Caso 2 Logística: channels C1–C5, policy POL-01..POL-08, Cuadro D components). VLAN segmentation and routing do not change.
Git repository on `main` since 2026-11-13 (L11), shared with classmates through GitHub: one branch per task and a pull request, Conventional Commits (`docs/COLABORAR.md`); evidence still goes in `## Tasks` and `## Log`. Test runner: none (functional checks on the live lab). Delivery: phases 0–5, each one leaves the lab working and can be the stopping point.
Status: phase 0 DONE (2026-11-13, see L6 and "Phase 0 results"). Phase 1 in progress: T1.1 DONE (L7), T1.2 DONE (L9); next T1.3. Phases 2–5 pending.

## How to work on this document (read first)

- The Spanish docs live in `docs/` (index: `README.md` at the project root). Bare names in this document (`PKI.md`, `VPN_VEHICULO.md`, ...) mean files in `docs/`; a bare `INFRAESTRUCTURA.md` in a task means the new `docs/INFRAESTRUCTURA_ACTUAL.md` (see next rule); new docs go there too and get a row in `README.md`. From `docs/`, links to configs and images are `../configs/...` and `img/...`.
- `docs/linea-base/INFRAESTRUCTURA.md` is the frozen LEGACY BASELINE (user decision, L4): read it, never edit it. It describes the lab before the hardening and the user reports improvements against it. Wherever a task below says to update `INFRAESTRUCTURA.md`, write to the new `docs/INFRAESTRUCTURA_ACTUAL.md` instead (state after the hardening, plus a "before → after" row per change) and add it to `README.md`. The baseline already lags the live topology: `CiscoRTT-1`, the `SIEM` VM and `Mint-1` are no longer in `eval1.gns3` (17 nodes; the Kali node is named `Kali`; Gi3/2 is free). For the node and link list, `eval1.gns3` is the source of truth.
- Read `docs/linea-base/INFRAESTRUCTURA.md`, `docs/VPN_VEHICULO.md`, `docs/WAZUH_DOCKER.md` and `docs/HOJA_TRAMPA.md` before touching the lab. Credentials: `docs/linea-base/INFRAESTRUCTURA.md` §10. The report is `docs/informe.pdf` (`pdftotext -layout`).
- The GNS3 server was NOT running when this roadmap was written (2026-11-13). Start it and the nodes first; ask the user if the project does not open.
- OPNsense is configured through the serial console with PHP scripts that use the OPNsense models (`configs/opnsense/lib.php`). Add one new script per task; do not edit `config.xml` by hand.
- A GNS3 Docker node cannot change image: rebuild the image, then delete and recreate the node (same name, port, static IP file under `project-files/docker/`).
- Before each task: back up what it changes (`eval1.gns3` copy, OPNsense `config.xml` copy under `/conf/backup`, `show running-config` of the switch). After each task: run its checks, record observed results in the Tasks table, update the Spanish docs listed in the task.
- After each task, also update `docs/INFORME_MEJORAS.md` (user request L8): before → after row, verification, and every environment limitation or deviation. Rules in the project `CLAUDE.md`.
- Order matters: phase 1 (PKI) blocks 2, 3, 4 and 5. Inside a phase, tasks run in ID order.
- Timebox every item marked VERIFY to about 15 minutes. If it fails, take the stated fallback and log it; do not improvise a third design.
- Never leave the lab without admin access: keep the serial consoles as the recovery path and test `WEBTERM-ADMIN → https://10.99.0.1` after every firewall rule change.
- Stop and ask the user only for: deleting a node other than the ones a task names, anything that needs Internet downloads beyond Docker images / apk / apt packages, or a fallback that drops a spec.

## Specs

Quoted strings are from `informe.pdf`.

- S1 (C2, POL-02, POL-05). Vehicle tunnel: "túnel IPsec IKEv2 terminado en el concentrador VPN del NGFW perimetral, con cifrado ESP ChaCha20-Poly1305 (RFC 7634), intercambio X25519 y autenticación mutua con certificados ECDSA P-256". "se habilita MOBIKE (RFC 4555)". No PSK left.
- S2 (POL-05). "el túnel no da acceso a todo". The vehicle reaches only the WMS (`odoo-1`, HTTPS). The VLAN 10 ↔ vehicle Child SA and allow-all rules 10 and 200 are removed.
- S3 (C1, POL-01). "TLS 1.3 terminado en el propio servidor WMS, con la suite TLS_AES_128_GCM_SHA256, intercambio de claves X25519 [...] y certificado de servidor ECDSA P-256". Odoo is reachable only on 443; 8069 is not reachable from any other host.
- S4 (C5, POL-01). "Syslog sobre TLS 1.3 (RFC 5425) con TLS_AES_128_GCM_SHA256, X25519 y autenticación mutua con certificados ECDSA P-256". "Los equipos que solo envían Syslog UDP lo hacen hacia un relé Syslog ubicado en su mismo segmento de gestión (VLAN 99), que reenvía por TLS. Así, el tramo sin cifrar nunca cruza segmentos."
- S5 (POL-01, §7.1). "Se prohíbe el texto claro (HTTP, Telnet, FTP, Syslog UDP fuera de su segmento)". "NGFW bloquea protocolos en texto claro entre VLAN y hacia Internet". Firewall permits "solo los flujos de la sección 2.1".
- S6 (POL-04, Cuadro D). "Jerarquía de dos niveles: CA raíz fuera de línea (RSA-3072, vigencia 10 años, clave en token HSM) y CA emisora (RSA 3072, 5 años, clave en HSM de red). Certificados finales: 1 año los internos y 2 años los de socios. Revocación: CRL de la emisora cada 7 días [...] OCSP en el concentrador VPN y la pasarela AS2." CA emisora and HSM live in VLAN 99. "Claves privadas no exportables".
- S7 (POL-06, §7.3). "IPS en línea en el NGFW (tráfico de Internet y tráfico de C2/C4 ya descifrado en el concentrador); IDS de red pasivo en un puerto espejo del switch L3 para el tráfico entre VLAN; IDS de host en el servidor WMS y la pasarela AS2, donde termina el TLS; SIEM correlaciona."
- S8 (C4, POL-05). "IPsec IKEv2, en el mismo concentrador del NGFW que C2, con cifrado ESP AES-256-GCM (RFC 4106), intercambio X25519 y autenticación con certificado personal ECDSA P-256 más segundo factor (MFA)". "Tras autenticarse, el usuario recibe una IP del pool VPN. Las reglas del NGFW limitan a los supervisores a la aplicación WMS (VLAN 20) y solo el perfil de administrador alcanza la VLAN 99." "Ningún servicio administrativo se expone a Internet." "Cierre de sesión tras 15 minutos de inactividad".
- S9 (C3a, Cuadro D). Pasarela AS2: "Servidor dedicado en VLAN 20, publicado por el NGFW solo en HTTPS". Transport "HTTPS con TLS 1.3 y autenticación mutua (mTLS) terminado en la pasarela AS2, no en el router. Usa suite TLS_AES_256_GCM_SHA384 y X25519." Message: "cifrado con AES-256-GCM (estructura AuthEnvelopedData de CMS) [...] firmado con RSA-3072 y SHA-256. El socio devuelve un MDN firmado". CBC only as the registered exception of §3.3.
- S10 (ACT-07, criterion 3 of the lab). Network gear is managed only from VLAN 99 and never in clear text. Each device has its own keys.
- S11. Spanish documentation updated (new `INFRAESTRUCTURA_ACTUAL.md`; `VPN_VEHICULO.md`, `WAZUH_DOCKER.md`, `HOJA_TRAMPA.md`, new `PKI.md`; `INFRAESTRUCTURA.md` untouched), with a deviations table: report requirement → what the lab does → why.

Out of scope (application or cloud, no topology effect; list them in the deviations table): MDM and Keystore attestation, Argon2id, per-record HMAC in the WMS, WORM storage and signed log checkpoints, S/MIME mail (C3b), EDR, TPM / smart cards, post-quantum roadmap.

Assumptions (architect defaults, not asked; the user may overrule):

- A1. The vehicle stays modelled as a site-to-site OPNsense (`FW-VEHICULO-01`), not a phone client. The device certificate identifies the vehicle router.
- A2. The report's "switch multicapa (L3)" is the L2 switch plus firewall-on-a-stick of the lab. Not changed.
- A3. HSM is simulated with SoftHSM2 inside the CA node. Root CA is a node with no link.
- A4. "MFA" for C4 = certificate (first IKEv2 auth round) + per-user EAP credential (second round). TOTP through RADIUS is a stretch goal, not required.
- A5. `Kali` is linked only in phase 5, as the test attacker. (`RTR-ZONA-CENTRO`, the `SIEM` VM and `Mint-1` were already removed from the project by the user.)

## Target design

### New nodes

| Node | Type / image | Where | IP | Role |
|---|---|---|---|---|
| `PKI-CA` | Docker, new image `pki-ca-gns3` (alpine, openssl, softhsm2, lighttpd) | `CiscoSW-1` Gi0/1 → VLAN 99 | 10.99.0.10/25 | Issuing CA, CRL + OCSP |
| `CA-RAIZ` | Docker, same image | no link | — | Offline root, signs only the issuing CA |
| `RELE-SYSLOG` | Docker, new image `rele-syslog-gns3` (alpine, rsyslog + gnutls) | `CiscoSW-1` Gi0/3 → VLAN 99 | 10.99.0.11/25 | UDP 514 in, TLS 6514 out |
| `IDS-RED` | Docker, new image `ids-gns3` (Suricata + Wazuh agent), 2 NICs | eth0: mirror/tap; eth1: `CiscoSW-1` Gi3/0 → VLAN 30 | eth0 none; eth1 192.168.30.8/24 | Passive network IDS |
| `PASARELA-AS2` | Docker, new image `as2-gns3` (OpenAS2 + Wazuh agent) | `CiscoSW-1` Gi1/3 → VLAN 20 | 192.168.20.6/24 | AS2 gateway |
| `SW-INTERNET` | GNS3 Ethernet switch | `ISP-WAN` Gi0/3 | — | "Public Internet" segment 192.0.2.0/24 (RFC 5737), gateway 192.0.2.1 |
| `SOCIO-B2B` | Docker, `as2-gns3` | `SW-INTERNET` | 192.0.2.10/24 | Partner AS2 endpoint |
| `REMOTO-SUPERVISOR` | Docker, new image `vpn-cliente-gns3` (alpine, strongswan, curl) | `SW-INTERNET` | 192.0.2.20/24 | C4 client, supervisor profile |
| `REMOTO-ADMIN` | Docker, `vpn-cliente-gns3` | `SW-INTERNET` | 192.0.2.30/24 | C4 client, admin profile |

Dockerfiles go under `docker/<image>/`, like `docker/odoo-gns3/`.

### Switch port plan (`SW-ZONA-CENTRO`)

| Port | Today | Target |
|---|---|---|
| Gi0/1 | VLAN 10, free | VLAN 99 → `PKI-CA` |
| Gi0/3 | VLAN 10, free | VLAN 99 → `RELE-SYSLOG` |
| Gi1/3 | VLAN 20, free | VLAN 20 → `PASARELA-AS2` |
| Gi3/0 | VLAN 30, free | VLAN 30 → `IDS-RED` eth1 |
| Gi2/0 | VLAN 20, free | SPAN destination → `IDS-RED` eth0 (only if SPAN works, see T4.2) |
| Gi1/0 | VLAN 10, stuck uBridge | freed by the switch reboot of T2.4 → `Kali` in phase 5 |

### Addressing and names

- VPN pools on `FW-ZONA-CENTRO`: supervisors 10.10.50.0/27, admins 10.10.50.32/27.
- `ISP-WAN` Gi0/3: 192.0.2.1/24, `ip nat enable`, no NAT between 192.0.2.0/24 and 203.0.113.0/30 (partners and remote users arrive with their real address).
- Certificate identities (SAN): `fw-centro.empresa.local`, `vehiculo-01.empresa.local`, `wms.empresa.local` (+ IP 192.168.20.5), `siem.empresa.local` (+ IP 192.168.30.5), `rele-syslog.empresa.local`, `as2.empresa.local` (+ IP 203.0.113.2), `as2.socio.example`, users `supervisor1@supervisores.empresa.local`, `admin1@admins.empresa.local`.
- PKI: root RSA-3072 / 10 y; issuing RSA-3072 / 5 y; internal end entities ECDSA P-256 / 1 y; AS2 signing and partner certificates RSA-3072 / 2 y; issuing CRL valid 7 days at `http://10.99.0.10/crl/emisora.crl`, OCSP on `PKI-CA` port 2560.

### Target firewall rules on `FW-ZONA-CENTRO`

Default deny stays. Replaces the table in `VPN_VEHICULO.md` §3.3.

| # | Interface | Source → destination | Ports | Change |
|---|---|---|---|---|
| 10 | VLAN 10 | VLAN 10 → `RED_VEHICULO` | all | REMOVE (S2) |
| 20 | VLAN 10 | VLAN 10 → `WAZUH-MANAGER` | 1514, 1515 TCP; 6514 TCP | 514 removed (S4) |
| 25 | VLAN 10 | VLAN 10 → 192.168.20.5 | 443 TCP | was 8069 (S3) |
| 30 | VLAN 10 | VLAN 10 → not RFC1918 | 53, 123, 443 | 80 removed (S5) |
| 40 | VLAN 10 | VLAN 10 → not RFC1918 | ICMP | keep |
| 50 | VLAN 20 | VLAN 20 → `WAZUH-MANAGER` | 1514, 1515, 6514 TCP | 514 removed |
| 55 | VLAN 20 | 192.168.20.6 → not RFC1918 | 443 TCP | NEW: AS2 outbound to partners |
| 60 | VLAN 30 | VLAN 30 → 192.168.30.1 | 53, 123 | keep |
| 70 | VLAN 99 | VLAN 99 → VLAN 10/20/30 | 22, 443 TCP; 161 UDP only if SNMPv3 | SNMP v1/v2c is clear text: drop 161 unless a device uses v3 |
| 80 | VLAN 99 | VLAN 99 → VLAN 10/20/30 | ICMP | keep |
| 90 | VLAN 99 | VLAN 99 → `SIEM_WAZUH` | 443, 1514, 1515, 5601, 9200, 55000, 6514 | 514 removed, 6514 added (relay) |
| 95 | any internal | internal hosts → 10.99.0.10 | 80, 2560 TCP | NEW: CRL/OCSP (signed objects; register as POL-07 exception) |
| 200 | IPsec | `RED_VEHICULO` → VLAN 10 | all | REMOVE (S2) |
| 210 | IPsec | `RED_VEHICULO` → 192.168.20.5 | 443 TCP | was 8069 |
| 220 | IPsec | `POOL_SUPERVISORES` → 192.168.20.5 | 443 TCP | NEW (S8) |
| 230 | IPsec | `POOL_ADMINS` → VLAN 99 and VLAN 10/20/30 | 22, 443 TCP, ICMP | NEW (S8) |
| 300–320 | WAN | any → WAN IP | UDP 500, UDP 4500, ESP | keep |
| 330 | WAN | any → 192.168.20.6 (port forward from WAN IP) | 443 TCP | NEW (S9); TCP passthrough, TLS ends on the gateway |

## Tasks

All tasks: route inline, status pending. Each task lists Do / Check / Docs.

### Phase 0 — Baseline and unknowns (no behaviour change)

| ID | Specs | Task |
|---|---|---|
| T0.1 | all | Do: start GNS3 and all linked nodes; copy `eval1.gns3` to `eval1.gns3.pre-hardening`; export both OPNsense `config.xml` and the switch running-config to `configs/backup-pre-hardening/`. Check: the tests of `VPN_VEHICULO.md` §5 still pass (tunnel up, `alpine-1` → Odoo 8069 = 303, Wazuh dashboard opens from `WEBTERM-ADMIN`). |
| T0.2 | S1, S8 | VERIFY on `FW-ZONA-CENTRO` (`swanctl --list-algs`, GUI field list of VPN → IPsec → Connections): `chacha20poly1305`, `x25519`, `aes256gcm16`, MOBIKE toggle, multiple remote auth rounds, pools, ECDSA certificates in System → Trust. Record the exact model field names for the scripts. Fallback if X25519 is missing: `ecp256`, logged as deviation. |
| T0.3 | S4 | VERIFY: OPNsense remote syslog offers transport `tls4` with a client certificate; Wazuh 4.14 `<remote>` syslog has no TLS (expected: none). Outcome fixes the design of T3.1. |
| T0.4 | S7 | VERIFY on `SW-ZONA-CENTRO`: `monitor session 1 source interface Gi0/0 both` / `destination interface Gi2/0`, then `tcpdump` on a test container on Gi2/0 must show frames of other VLANs. Expected to FAIL on IOSv-L2. Fallback (pre-approved): insert a GNS3 Ethernet hub `TAP-TRUNK` between `FW-ZONA-CENTRO` vtnet0 and `CiscoSW-1` Gi0/0, `IDS-RED` eth0 on the hub; Gi2/0 stays free. |
| T0.5 | S9 | VERIFY: OpenAS2 runs in Docker offline-friendly, supports RSA-3072/SHA-256 signing, signed MDN, and AES-256-GCM (`AuthEnvelopedData`). Fallback: AES-256-CBC with sign-then-encrypt + signed MDN, registered as the §3.3 exception. |
| T0.6 | S7 | VERIFY: Suricata (IPS mode) is installable on OPNsense 26.7 in this lab (needs Internet through `ISP-WAN` for rule download; 2 GB RAM may be tight — raise the node to 4 GB if it swaps). |

### Phase 0 results (2026-11-13, observed on the live lab)

| ID | Status | Evidence | Consequence for later tasks |
|---|---|---|---|
| T0.1 | done | `eval1.gns3.pre-hardening` and `configs/backup-pre-hardening/` (`eval1.gns3`, both `config.xml` with matching sha256, switch and ISP running-config, identical to `configs/*_running-config.txt`); `/conf/backup/config-pre-hardening.xml` on both OPNsense. Tunnel `ESTABLISHED` (2 Child SAs, `AES_GCM_16-128`, `ECP_256`); `alpine-1` and `TABLET-CONDUCTOR` → `192.168.20.5:8069` = `303 SEE OTHER`; tablet → 192.168.20.1 100 % loss; `WEBTERM-ADMIN` → Wazuh 5601 = 200 and `https://10.99.0.1` = 200; clocks correct (UTC). | none |
| T0.2 | done | strongSwan has `CHACHA20_POLY1305`, `CURVE_25519` (also `ML_KEM_*`), plugins `revocation`, `curl`, `eap-mschapv2`, `eap-tls`, `eap-radius`. Model `OPNsense/IPsec/Swanctl.xml`: Connection `proposals`, `mobike` (bool), `pools`, `send_cert`; local/remote `round`, `auth` (`psk`, `pubkey`, `eap-tls`, `eap-mschapv2`, `xauth-pam`, `eap-radius`), `certs`, `cacerts`, `eap_id`; child `esp_proposals`, `close_action`, `dpd_action`. Proposal keys: IKE `chacha20poly1305-sha256-x25519`, ESP `chacha20poly1305-x25519`, C4 ESP `aes256gcm16-x25519`. Trust `key_type`: `prime256v1`, `3072`. | T2.1: use `chacha20poly1305-sha256-x25519` (the roadmap's `prfsha256` name does not exist). T5.2: round 2 = `eap-mschapv2` (A4). **No inactivity field** in the model: the 15-minute timeout of S8 needs a `swanctl` `conf.d/*.conf` include (`swanctl.conf` has `include conf.d/*.conf`) or a template override — VERIFY in T5.2; fallback: DPD + `rekey/reauth` 900 s, logged as deviation. |
| T0.3 | done | OPNsense `Syslog.xml` destination `transport` offers `tls4`/`tls6` and a `certificate` field (client cert); the syslog-ng template verifies the server with `ca-dir("/etc/ssl/certs")` and has no TLS version or cipher pin. Wazuh 4.14.10 `<remote>` is `syslog`/`udp` 514 only (no TLS). Manager image is Amazon Linux 2023 without rsyslog; `rsyslog` and `rsyslog-openssl` 8.2204 are installable with dnf (no `rsyslog-gnutls`). | T3.1: use rsyslog with the **openssl** driver (`imtcp` `StreamDriver.Name="ossl"`), TLS 1.3 and the suite pinned on the listener. T1.2/T3.2: the lab root and issuing CA must be in the OPNsense system trust store (System → Trust → CA, "trust store" option) or the TLS syslog client rejects the server. |
| T0.4 | done | `monitor session 1 source interface Gi0/0 both` + `destination interface Gi2/0` accepted on IOSv-L2 (encapsulation native). Temporary container `TEST-SPAN` on Gi2/0 + GNS3 link capture: 132 frames in ~20 s with VLAN 10↔20 (ICMP, 8069), VLAN 99↔30 (5601), VLAN 10↔Internet and **clear-text syslog 192.168.30.1 → 192.168.30.5:514**. Test removed afterwards (session deleted, nothing saved, node deleted). | **SPAN works**: T4.2 uses Gi2/0 as SPAN destination; the `TAP-TRUNK` hub fallback is not needed. Frames arrive untagged. |
| T0.5 | done | OpenAS2 source (`BCCryptoHelper.getEncryptionOID`): only 3DES, RC2, CAST5, IDEA, AES-128/192/256-CBC and AES-WRAP; no GCM branch and no `CMSAuthEnvelopedData`. Digests include `sha-256`. No official Docker image: build from the OpenAS2 GitHub release/Dockerfile. | T5.3: **fallback taken**: AES-256-CBC, sign-then-encrypt (RSA-3072 / `sha-256`) + signed MDN, registered as the §3.3 exception in the deviations table. Downloading the OpenAS2 release from GitHub is beyond Docker/apk/apt: ask the user before T5.3. TLS 1.3 + mTLS on the OpenAS2 listener: VERIFY in T5.3; fallback: TLS terminated by a proxy inside the same `PASARELA-AS2` container (still ends on the gateway). |
| T0.6 | done | Suricata 8.0.6 in OPNsense core (model `OPNsense/IDS`). ET Open reachable from `FW-ZONA-CENTRO` (`version.txt` = 11334). Node: 2 GB RAM, 2 vCPU, 1.47 GB free, no swap. | T4.1: raise `FW-ZONA-CENTRO` to 4 GB before enabling Suricata (node stop needed). IPS on `enc0` is not netmap-capable: VERIFY in T4.1; fallback: IPS on WAN, IDS (pcap) on `enc0`. |

### Phase 1 — PKI (blocks phases 2–5)

| ID | Specs | Task |
|---|---|---|
| T1.1 | S6 | Do: build `pki-ca-gns3`; create `CA-RAIZ` (no link) and `PKI-CA` (Gi0/1). Move Gi0/1 and Gi0/3 to VLAN 99 on the switch and save. Root key and issuing key generated inside SoftHSM2 tokens (non-extractable), accessed through the PKCS#11 provider. VERIFY fallback: file keys with mode 0600, logged as deviation. Because `CA-RAIZ` has no network, sign the issuing CSR by copying files through the host (`docker cp`). Check: `openssl verify -CAfile raiz.pem emisora.pem` OK; `PKI-CA` pings 10.99.0.1. |
| T1.2 | S6 | Do: scripts `emitir.sh <perfil> <nombre>` and `revocar.sh` on `PKI-CA` (profiles: servidor, equipo, usuario, socio); CRL regenerated by cron every 7 days and served by lighttpd; `openssl ocsp` responder on 2560; add firewall rule 95. Issue every certificate named in "Addressing and names". Private keys are generated on the device that uses them whenever the device can produce a CSR (OPNsense, strongSwan clients); otherwise issued as PKCS#12 and deleted from the CA. Check: CRL downloads from `WEBTERM-ADMIN`; OCSP answers `good` for one cert and `revoked` after revoking a throwaway cert. Docs: new `PKI.md` (hierarchy, lifetimes, commands, where each cert lives). |
| T1.3 | S10 | Do: on both OPNsense, regenerate SSH host keys and replace the shared web GUI certificate with one issued by the CA (`fw-centro`, `vehiculo-01`); import the CA chain in `WEBTERM-ADMIN` Firefox. Check: the two firewalls show different SSH fingerprints and certificate serials; GUI opens without warning. |

### Phase 1 results (observed on the live lab)

| ID | Status | Evidence | Consequence for later tasks |
|---|---|---|---|
| T1.1 | done (2026-11-13) | Image `docker/pki-ca-gns3/` (alpine 3.24, openssl 3.5, softhsm 2.7, libp11 `pkcs11prov`, opensc, lighttpd). Nodes `PKI-CA` (Gi0/1, 10.99.0.10/25, gw 10.99.0.1) and `CA-RAIZ` (0 adapters, only `lo`); both persist `/srv/ca` and `/var/lib/softhsm/tokens`. Switch Gi0/1 (`PKI-CA`) and Gi0/3 (`RELE-SYSLOG (reservado)`) in VLAN 99, `write memory`; `configs/SW-ZONA-CENTRO_running-config.txt` patched to match. Root `CN=Empresa CA Raiz` RSA-3072 until 2036-11-10 (sha256 `32:53:E0:8F:…:40:E0:8D`); issuing `CN=Empresa CA Emisora` RSA-3072, `pathlen:0`, CDP `http://10.99.0.10/crl/raiz.crl`, until 2031-11-12 (serial `4BC221DD…FA18292A`). Both keys: `sensitive, never extractable` (SoftHSM2 fallback not needed); no `PRIVATE KEY` file under `/srv/ca`. Checks after a node stop/start: `openssl verify -CAfile raiz.pem emisora.pem` OK, also with `-crl_check` and the root CRL; `pki-emisora-instalar` refuses a cert that does not match the token key. | **Check deviation:** `FW-ZONA-CENTRO` drops ICMP to its own address from VLAN 99 for every host (also `WEBTERM-ADMIN`; only VLAN 99 → VLAN 10/20/30 ICMP is allowed), so "pings 10.99.0.1" was replaced by ARP resolved + `https://10.99.0.1` reachable. No firewall change. T1.2: CA PINs are random, in `/srv/ca/<ca>/private/pin` (0600), read by libp11 through `pin-source=file:`; root CRL (365 days) must be served at `/crl/raiz.crl`; copy files between nodes with `docker exec … cat` (GNS3 mounts volumes inside the container, `docker cp` does not see them); libp11 prints diagnostics on stdout, so write key/CSR output with `-out`. |
| T1.2 | done (2026-11-13) | Image `pki-ca-gns3` rebuilt, `PKI-CA` and `CA-RAIZ` recreated (extra_hosts toggle + stop/start; volumes kept). Commands `pki-emitir <servidor\|equipo\|usuario\|socio> <name> [--csr F] [--san L]` (subject and SAN forced by the CA; key type checked: P-256 1 y, socio RSA-3072 2 y; PKCS#12 fallback without CSR), `pki-revocar`, `pki-crl` (7-day CRL, cron daily 03:17 + at start + on revoke), `pki-publicar`, `pki-ocsp-init` (OCSP signer P-256 in token, EKU OCSPSigning, until 2027-11-13), `pki-ocsp`. lighttpd: `/ca/`, `/crl/`, `/certs/` on 80 and OCSP proxy on 2560 → `openssl ocsp` on 2561. Firewall: `configs/opnsense/pki-regla.php` (aliases `PKI_CA`, `PUERTOS_PKI`; rule 95 on opt1/opt2/opt3, TCP 80/2560), backup `/conf/backup/config-pre-t1.2.xml`. Checks: CRL HTTP 200 from VLAN 99/10/20/30; VLAN 10 → 10.99.0.10:443 blocked; throwaway `prueba-ocsp.empresa.local` (key made on `WEBTERM-ADMIN`) OCSP `good` from VLAN 99 and 20, after `pki-revocar` `revoked` from both and serial `70F754D5…` in the new CRL; OCSP still answers with idle connections open; duplicate name and RSA-2048 CSR for P-256 profile rejected (throwaway containers); `WEBTERM-ADMIN` → `https://10.99.0.1` 200 and Wazuh 5601 302 (login redirect). Docs: new `docs/PKI.md`, `HOJA_TRAMPA.md`, `VPN_VEHICULO.md` §3.3 row 95 and script list, `INFORME_MEJORAS.md`, `README.md`. | **Scope decision:** end-entity certs are issued in the task that sets up each device (T1.3, T2.1, T2.2, T3.1, T3.3, T5.2, T5.3) so keys are generated on the device; list in `PKI.md` §4. **Defect fixed:** single-process `openssl ocsp` hung at 100 % CPU on an empty connection (`nc -z`); `-multi` fails in the container (`RAND_poll() failed`), so lighttpd fronts it. `openssl ocsp` re-reads `index.txt` only after the next request: `pki-publicar` sends a local query after each change. Use node stop/start, not the API `reload` (it left the container without `eth0`). Firewall console helper: lines ≤ 160 chars (FreeBSD canonical input limit). |

### Phase 2 — Harden what exists (highest risk reduction: R1, R2, R3, R7)

| ID | Specs | Task |
|---|---|---|
| T2.1 | S1 | Do: new script `configs/opnsense/vpn-certs.php centro|vehiculo`: both ends authenticate with `pubkey` (ECDSA P-256 certs from T1.2), identities unchanged; proposals pinned to IKE `chacha20poly1305-prfsha256-x25519` and ESP `chacha20poly1305-x25519`; MOBIKE on; revocation check against CRL/OCSP on the centre; delete both PSK entries. Check: `swanctl --list-sas` on both ends shows the pinned algorithms and certificate auth; no `PSK` in `swanctl --list-conns`; changing the vehicle's WAN address (release/renew on `ISP-WAN` pool or a second lease) keeps or re-establishes the SA; a revoked vehicle cert is refused (use a throwaway cert, then restore). |
| T2.2 | S3 | Do: rebuild `odoo-gns3` with nginx: listen 443, `ssl_protocols TLSv1.3`, cert `wms.empresa.local` ECDSA P-256, `ssl_conf_command Ciphersuites TLS_AES_128_GCM_SHA256`, groups X25519, proxy to 127.0.0.1:8069; Odoo `http_interface = 127.0.0.1`, `proxy_mode = True`. Recreate `odoo-1` (Gi1/2, 192.168.20.5; it has no database yet, nothing is lost — confirm that before deleting). Check from `alpine-1`: `openssl s_client -connect 192.168.20.5:443 -tls1_3` shows the suite, group and CA chain; `-tls1_2` fails; `:8069` refused; `postgres-1` still sees 192.168.20.5. |
| T2.3 | S2, S3, S5 | Do: new script `configs/opnsense/reglas-informe.php`: apply the target rule table for rules 10, 25, 30, 70, 200, 210 (the syslog rows wait for T3.2); remove the Child SA 192.168.10.0/24 ↔ 172.16.50.0/24 on both firewalls and the matching rule on `FW-VEHICULO-01`. Check: `TABLET-CONDUCTOR` → `https://192.168.20.5` answers, → any VLAN 10 host fails, → 192.168.20.5:8069 fails; `alpine-1` → `http://example.com` (80) blocked, `https://` OK; `WEBTERM-ADMIN` still opens the firewall GUI and Wazuh. Docs: `VPN_VEHICULO.md` §3.3–§5. |
| T2.4 | S10 | Do on `SW-ZONA-CENTRO`: `no interface Vlan10/20/30`; `logging source-interface Vlan99`; ACL `VTY_MGMT` permit 10.99.0.0 0.0.0.127 applied as `access-class` on VTY 0–4; `no ip http server`, `no ip http secure-server`; `service password-encryption`; fix the trunk description to `FW-ZONA-CENTRO vtnet0`; `write memory`; then reload the switch once (frees Gi1/0). Syslog target stays 192.168.30.5 until T3.3. Check: SSH to 10.99.0.2 works from `WEBTERM-ADMIN`; 192.168.10.2 no longer answers from `alpine-1`; all access ports come back in the right VLAN; Gi1/0 usable. Docs: `INFRAESTRUCTURA.md` §7 and findings 7, 8, 10; refresh `configs/SW-ZONA-CENTRO_*`. |
| T2.5 | S10 | Do: on both OPNsense, SSH `root` login by key only (`passwordauth = 0`, key of `WEBTERM-ADMIN`/`SoporteTI`), GUI and SSH listening only on MGMT. Change the lab passwords only if the user asks (the docs record `test123` on purpose). Check: password SSH refused, key SSH OK from VLAN 99. |

### Phase 3 — Logging over TLS (R5)

| ID | Specs | Task |
|---|---|---|
| T3.1 | S4 | Do: add a TLS syslog listener to the manager image (`docker/wazuh-gns3/manager`): rsyslog with gnutls on 6514, TLS 1.3 only, cert `siem.empresa.local`, `x509/name` client authentication against the lab CA; it hands messages to Wazuh locally (forward to 127.0.0.1:514/UDP, keeping the device address so `location` stays meaningful — VERIFY; fallback: write to a file read by a `<localfile>`). Restrict the existing `<remote>` UDP 514 to 127.0.0.1 and 192.168.30.0/24. Recreate `WAZUH-MANAGER` only if the image must change (check what persists in volumes first, see `WAZUH_DOCKER.md`). Check: `openssl s_client` to 192.168.30.5:6514 without a client cert is rejected. |
| T3.2 | S4, S5 | Do: `FW-ZONA-CENTRO` remote logging → `tls4` 192.168.30.5:6514 with client cert `fw-centro`; remove the UDP destination; apply the syslog rows of the rule table (20, 50, 90). Check: repeat the `logger ... Failed password` test of `WAZUH_DOCKER.md`; alert 5710 arrives; a capture on the VLAN 30 trunk shows no UDP 514 from 192.168.30.1. |
| T3.3 | S4 | Do: build `rele-syslog-gns3`, create `RELE-SYSLOG` (Gi0/3, 10.99.0.11): UDP 514 in from 10.99.0.0/25 only, forward by TLS to 192.168.30.5:6514 with cert `rele-syslog`. Point the switch to `logging host 10.99.0.11` (source Vlan99). Check: a switch event (`conf t` / exit → `%SYS-5-CONFIG_I`) appears in Wazuh; UDP 514 is seen only inside VLAN 99. Docs: `WAZUH_DOCKER.md`, `INFRAESTRUCTURA.md` §3–§5. |
| T3.4 | S7 | Do: Wazuh agent on `odoo-1` (host IDS at the TLS endpoint): add it to the `odoo-gns3` image, enrol against 192.168.30.5 (1514/1515), monitor nginx and Odoo logs. Do this in the same rebuild as T2.2 if both phases are approved together. Check: agent `Active` in the dashboard; a failed Odoo login raises an event. |

### Phase 4 — Detection (POL-06)

| ID | Specs | Task |
|---|---|---|
| T4.1 | S7 | Do: Suricata on `FW-ZONA-CENTRO` in IPS mode on WAN and on the IPsec interface (`enc0`, traffic already decrypted), ET Open rules, daily rule update cron, EVE alerts to syslog (so they reach Wazuh through T3.2). Check: a test signature (`curl http://testmynids.org/uid/index.html` from a VPN client or an EICAR-style test rule) produces an alert in Wazuh. If T0.6 failed: IDS mode only, logged as deviation. |
| T4.2 | S7 | Do: build `ids-gns3`, create `IDS-RED`: eth0 on the SPAN port Gi2/0 or on the `TAP-TRUNK` hub (per T0.4), promiscuous, no IP; eth1 on Gi3/0 (192.168.30.8) with a Wazuh agent shipping `eve.json`. Local rules: TLS older than 1.3 between VLANs, clear-text HTTP/Telnet/FTP/UDP 514 between VLANs, port scans, ARP spoofing (use `arpwatch` or Suricata's decoder events — VERIFY which works; the hub tap sees the trunk only, so ARP inside an access VLAN is visible only as it reaches the gateway). Add 192.168.30.8 to alias `SIEM_WAZUH` only if needed. Check: `nmap` from `SoporteTI` to VLAN 10 and an `openssl s_client -tls1_2` attempt each raise an alert in Wazuh. Docs: `INFRAESTRUCTURA.md` (topology diagram, inventory, links). |

### Phase 5 — New channels (C4 and C3a) and validation

| ID | Specs | Task |
|---|---|---|
| T5.1 | S8, S9 | Do: `ISP-WAN` Gi0/3 → 192.0.2.1/24, `no shutdown`, `ip nat enable`; exclude 192.0.2.0/24 ↔ 203.0.113.0/30 from NAT; add `SW-INTERNET`. Save and refresh `configs/ISP-WAN_running-config.txt`. Check: a test container on 192.0.2.0/24 pings 203.0.113.2's gateway side and reaches Internet. |
| T5.2 | S8 | Do: new script `configs/opnsense/vpn-remotos.php`: two road-warrior connections on `FW-ZONA-CENTRO`, selected by remote identity (`*@supervisores.empresa.local`, `*@admins.empresa.local`), auth round 1 `pubkey` (personal ECDSA cert), round 2 EAP with a per-user credential (A4), ESP `aes256gcm16-x25519`, pools 10.10.50.0/27 and 10.10.50.32/27, inactivity timeout 900 s, rules 220 and 230. Build `vpn-cliente-gns3`; create `REMOTO-SUPERVISOR` and `REMOTO-ADMIN`. Check: supervisor gets a pool IP, opens `https://192.168.20.5`, cannot reach 10.99.0.1 nor 192.168.20.4; admin reaches `https://10.99.0.1` and SSH to 10.99.0.2; wrong second factor → no tunnel; revoked cert → no tunnel; idle 15 min → SA deleted; from 192.0.2.x without a tunnel, 203.0.113.2 exposes only UDP 500/4500 and TCP 443 (after T5.3). Docs: new section in `VPN_VEHICULO.md` or new `VPN_REMOTOS.md`. |
| T5.3 | S9 | Do: build `as2-gns3`; create `PASARELA-AS2` (Gi1/3, 192.168.20.6, with Wazuh agent) and `SOCIO-B2B` (192.0.2.10). Gateway listens on 443 with TLS 1.3, `TLS_AES_256_GCM_SHA384`, client certificate required; partner certificate issued by the lab CA with the 2-year partner profile. Firewall: port forward WAN 443 → 192.168.20.6 (rule 330) and rule 55. Message security per T0.5. Check: `SOCIO-B2B` sends a test manifest, the gateway stores it and returns a signed MDN whose MIC matches; the reverse direction works; a connection without client cert is rejected; a tampered payload fails signature verification; events visible in Wazuh. Docs: new `AS2.md`. |
| T5.4 | all | Do: link `Kali` to Gi1/0 (VLAN 10) as the attacker of R7 and run the acceptance matrix below from scratch after a full project stop/start (proves persistence). Unlink `Kali` afterwards. |
| T5.5 | S11 | Do: final documentation pass in Spanish: diagrams, inventory, links, rule table, credentials table (no private keys in docs), `HOJA_TRAMPA.md` commands for the new nodes, and the deviations table (A1–A5, every fallback taken, out-of-scope items). |

### Acceptance matrix (T5.4)

| Report item | Test | Expected |
|---|---|---|
| POL-01 / R7 | From `Kali` in VLAN 10: ARP-spoof `alpine-1` ↔ gateway and capture the Odoo session | Only TLS 1.3 records; `IDS-RED` or Wazuh raises an alert |
| POL-01 | Capture on the trunk for 5 min during normal use | No HTTP, Telnet, FTP or UDP 514 between VLANs |
| POL-02 | `swanctl --list-sas`, `openssl s_client` on 443/6514 | Only the algorithms of the report per channel |
| POL-04 | Revoke one certificate | Refused by VPN, syslog TLS and AS2 within one CRL/OCSP refresh |
| POL-05 | Scope tests of T2.3 and T5.2 | Vehicle and supervisor reach only the WMS; only admins reach VLAN 99 |
| POL-06 | Trigger tests of T4.1, T4.2, T3.4 | Alerts in the Wazuh dashboard for each sensor |
| S10 | Switch and firewalls from VLAN 10/20/30 | No management service reachable |
| Persistence | Stop and start the whole project | All of the above still pass |

## Risks and mitigations

- Admin lockout while changing rules or SSH: serial consoles stay untouched; one firewall change at a time, GUI test after each.
- Recreating Docker nodes loses container state: confirm what is in volumes before deleting (`odoo-1` has no DB yet; Wazuh state is described in `WAZUH_DOCKER.md`).
- Host load: nine new nodes plus Suricata. Start phase 5 nodes only when testing them; `SoporteTI`/`Mint-1`/`Kali` can stay off.
- Clock: certificates need correct time on every node; check `date` on OPNsense and containers before T2.1.
- A VERIFY that fails must end in the stated fallback and a line in the deviations table, never in a silent downgrade.

## Log

- L1 (2026-11-13): "Te dejé en el contexto el informe que necesito usar para mejorar la seguridad del proyecto. Quiero que hagas un análisis y me des un reporte de lo que necesito modificar en la topología" (original request). Result: gap analysis delivered in chat; nothing changed.
- L2 (2026-11-13): "No empieces aún, tú eres el arquitecto, genera un roadmap detallado y luego mandamos a opus a trabajar". Result: this document. Implementation not started; waiting for the user's go-ahead.
- L3 (2026-11-13): evidence used — `informe.pdf` (36 pages, read in full as text), `eval1.gns3` (17 nodes, 15 links), `configs/opnsense/*.php` (PSK auth, no pinned proposals, syslog `udp4`), switch running-config (SVIs 10/20/30, `ip http server`, no VTY `access-class`). GNS3 server was not running, so nothing was verified live; phase 0 exists for that.
- L4 (2026-11-13): asked whether to update `INFRAESTRUCTURA.md` and this roadmap to the current topology, the user answered: "No, déjalo como legacy, me sirve para reportar las mejoras". `docs/linea-base/INFRAESTRUCTURA.md` is frozen as the baseline; the post-hardening state goes to a new `docs/INFRAESTRUCTURA_ACTUAL.md`. S11, A5 and the working rules were adjusted; no task reopened (none started).
- L5 (2026-11-13): "documenta que ese archivo es de infraestructura, para que los proximos agentes que trabajen en el proyecto, o yo mismo, no tengamos confusiones, y déjalo separado". Baseline moved to `docs/linea-base/INFRAESTRUCTURA.md` with a frozen-baseline banner; rule added to the project `CLAUDE.md`.
- L6 (2026-11-13): "Sí, arranca Fase 0". GNS3 server running with the project open; all linked nodes started except `SoporteTI` and `Kali`. T0.1–T0.6 executed; results and design consequences in "Phase 0 results". Two outcomes differ from the expectations: SPAN works on IOSv-L2 (no hub tap needed) and OpenAS2 has no AES-GCM (CBC fallback). The live lab is unchanged apart from backups. Next: phase 1 (T1.1, PKI).
- L7 (2026-11-13): "Sí, arranca T1.1". T1.1 done, see "Phase 1 results". The roadmap check "PKI-CA pings 10.99.0.1" was wrong for this firewall policy (no ICMP to the firewall itself from VLAN 99); replaced by an L3 reachability check, firewall left unchanged. Next: T1.2.
- L8 (2026-11-13): "Ve documentando, a medida que vamos haciendo cambios, ve avanzando en un informe en .md con las mejoras del proyecto e incluye las limitaciones en el entorno virtualizado. Que quede todo documentado, para mí, mis compañeros y el profesor." Created `docs/INFORME_MEJORAS.md` (phase 0, T1.1, limitations L1–L7, deviations D1–D5, out of scope), linked from `README.md`; upkeep rule added to `CLAUDE.md` and to "How to work on this document". It also seeds the S11 deviations table.
- L9 (2026-11-13): "Yes, start T1.2". T1.2 done, see "Phase 1 results". End-entity issuance moved to each device's task (keys generated on the device, which the task text itself prefers). OCSP responder fronted by lighttpd after a hang found during testing. Next: T1.3.
- L10 (2026-11-13): the user reconfigured the GNS3 server so classmates can connect: it now listens on the ZeroTier address 10.82.151.125:3080 with `auth = False`, `allow_console_from_anywhere = True` and `auto_start = False`. All nodes came up stopped. Started in order (switch, ISP, firewalls; then containers; `Kali`, `SoporteTI` off) and re-verified: switch Gi0/1/Gi0/3 in VLAN 99, rule 95 loaded, IPsec ESTABLISHED, PKI-CA HTTP/OCSP up (OCSP `revoked` for the T1.2 throwaway), CA-RAIZ only `lo`, CRL from VLAN 99/10/30, FW GUI 200, Wazuh dashboard 302 and indexer green, Odoo 303 from `alpine-1` and from the tablet over the VPN. The firewall console, previously left at a root shell, is now at `login:`. Consoles are at 10.82.151.125 (docs updated). Exposure recorded as L10 in `INFORME_MEJORAS.md`; tools: API helper without auth, console helpers use `GNS3_HOST`.
- L11 (2026-11-13): "prepara todo para subir la documentación a github para que mis compañeros puedan trabajar también en el proyecto". `git init -b main`, 88 files staged (about 0.8 MB), nothing committed or pushed yet. The user's `.gitignore` was kept and extended with `*config.xml`: the pre-hardening OPNsense backups embed the web GUI private key (`<prv>`) and the API key. Staged-content scan: no private key material, no personal data. New `docs/COLABORAR.md` (what is and is not in the repo, shared lab access, Git workflow, PR checklist), linked from `README.md`.
- L12 (2026-11-13): Unit 3 work (`odd/tasks/u3-perimetral.md`) applied part of T2.3 on `FW-ZONA-CENTRO`: rules 10 and 200 disabled, rule 30 without port 80, per-rule logging, final block+log rule 9000, schedule on rule 210. Still open in T2.3: remove the VLAN 10 Child SA on both firewalls (do it in T2.1), move the WMS to 443 (T2.2), syslog rows (T3.2).
