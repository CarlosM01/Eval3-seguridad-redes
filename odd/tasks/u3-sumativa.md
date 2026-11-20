# Unit 3 summative: bring the lab in line with the updated report

Objective: make the GNS3 lab match every technical claim of the updated summative report (`Informe_Evaluacion3_Sumativa_SeguridadRedes_ZonaCentro.docx`, teacher feedback applied) and capture the screenshots its Figures 1–10 ask for. Continues `u3-perimetral.md` (L3–L7) and `informe-hardening.md` (T2.1, T4.1, T5.2).
Branch: `feat/u3-sumativa`, stacked on `feat/gns3-control-scripts` (dd8853e commits the previous task's pending work). Runner: none (lab state is checked through consoles and the P-01..P-08 tests).

## Specs

- S1. User request (2026-11-20): "Actualiza el proyecto GNS3 con las nuevas mejoras solicitadas e incluye capturas de pantalla como evidencia para el informe".
- S2. DMZ: VLAN 40 `DMZ_B2B` 192.168.40.0/24 on CiscoSW-1 and `vlan040` on FW-ZONA-CENTRO (gateway .1); B2B server 192.168.40.10 with HTTPS 443; trunk allowed only "10,20,30,40,99"; NAT port forward 203.0.113.2:443 → 192.168.40.10:443 (R-05A); R-05B DMZ_B2B_SRV → WMS_ODOO TCP 8069, database 192.168.20.4:5432 blocked.
- S3. Aliases "WMS_ODOO = 192.168.20.5, BD_POSTGRES = 192.168.20.4, SIEM_WAZUH = 192.168.30.5, PKI_CA = 10.99.0.10, DMZ_B2B_SRV = 192.168.40.10, RED_VEHICULO = 172.16.50.0/24, POOL_VPN_REMOTA = 10.8.0.0/24"; schedule `HORARIO_DESPACHO`; rule table R-01, R-01B, R-02, R-03A, R-03B, R-04, R-05A, R-05B, R-06, R-07, R-08, R-99, all with log.
- S4. IPsec VPN-VEHICULO-01 with X.509 certificates from PKI-CA (Phase 1 public key), one Child SA 192.168.20.5/32 ↔ 172.16.50.0/24, ESP AES-GCM, DH ECP-256, NAT-T.
- S5. OpenVPN remote access: UDP 1194 on WAN, pool 10.8.0.0/24, AES-256-GCM, PKI-CA certificates, route only 192.168.20.5/32; R-03A allows WMS 8069, R-03B blocks 10.99.0.0/25 and the database. Client: Kali in the external zone.
- S6. Suricata in IPS mode (Netmap) on vtnet1 and vtnet0, ET Open rules, alerts to Wazuh.
- S7. Remote syslog FW-ZONA-CENTRO → WAZUH-MANAGER 192.168.30.5 UDP 514 (filterlog, IPsec/OpenVPN sessions, Suricata).
- S8. Events E-01 (vehicle out of schedule, R-99), E-02 (alpine-1 → 5432 repeated, Wazuh 87702), E-03 (Suricata drop from Kali/ISP), E-04 (OpenVPN client → 10.99.0.1:443, R-03B) with exact time.
- S9. Backups: `config-FW-ZONA-CENTRO.empresa.local-20261120.xml`, `config-FW-VEHICULO-01.empresa.local-20261120.xml`, `CiscoSW-1_running-config_20261120.txt` (kept out of Git: they hold private keys).
- S10. Health check: interfaces up, services running (pf, suricata, strongswan, openvpn, syslog-ng, unbound), CPU/RAM, `wazuh-control status`, indexer `_cluster/health`.
- S11. Tests P-01..P-08 of report §4.4 run and recorded; screenshots for Figures 1–10 in `docs/img/u3-sumativa/`.
- S12. Project rules: update `docs/INFORME_MEJORAS.md` (§1–§5), `README.md`, `docs/INFRAESTRUCTURA_ACTUAL.md`; never edit the baseline.

Assumptions: Kali joins the external zone on ISP-WAN Gi0/3 with 192.0.2.10/24 (RFC 5737 TEST-NET-1, gateway 192.0.2.1); the B2B node is a new Docker image `b2b-dmz-gns3` (nginx HTTPS) on CiscoSW-1 Gi1/2; FW-ZONA-CENTRO RAM raised to 4096 MB for Suricata.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2 | inline | done | B2B-DMZ (Gi1/3), REMOTO-SUPERVISOR (ISP Gi0/3) instead of Kali, FW 4096 MB |
| T2 | S2 | inline | done | VLAN 40, trunk 10,20,30,40,99; SVIs 10/20/30 removed + ACL MGMT_VTY (found SSH open from VLAN 10) |
| T3 | S2, S3, S7 | inline | done | `u3-sumativa-centro.php`: rules rebuilt, rdr 443, hybrid SNAT; syslog target already present |
| T4 | S4 | inline | done | `u3-ipsec-pki.php`: pubkey auth both sides, 1 Child SA, ECP-521 (no ECP-256 option) |
| T5 | S5 | inline | done | `u3-openvpn.php`: 10.8.0.2 connected; R-03B blocks logged; OCSP option logs "no OCSP configured" |
| T6 | S6 | inline | done | `u3-suricata.php`: Netmap on wan+opt5(vtnet0), ET scan/exploit/web_server, drop policy; 7 SIDs blocked |
| T7 | S8, S11 | inline | done | P-01..P-08 OK; E-01..E-04 in Wazuh (100210, 100212+87702, 100201, 100211) |
| T8 | S9, S10 | inline | done | `configs/backup-u3-sumativa/` (XML git-ignored); services/health captured |
| T9 | S11 | inline | done | 34 PNG in `docs/img/u3-sumativa/` |
| T10 | S12 | inline | done | U3_SUMATIVA.md, INFRAESTRUCTURA_ACTUAL.md, INFORME_MEJORAS §1–§5, README, PKI.md; commits 7adfdce (lab) and the docs commit that follows it on `feat/u3-sumativa` |

## Log

- L1 (2026-11-20): "Informe_Evaluacion3_Sumativa_SeguridadRedes_ZonaCentro.docx  Ese es el informe actualizado con las retroalimentaciones del profesor.  Actualiza el proyecto GNS3 con las nuevas mejoras solicitadas e incluye capturas de pantalla como evidencia para el informe".
- L2 (2026-11-20): gap analysis. Already in the lab: VLAN 10/20/30/99, R-xx rules with log, `HORARIO_DESPACHO`, IPsec with PSK and two Child SA, Wazuh 87702. Missing: S2–S10. All nodes stopped at start.
- L3 (2026-11-20): the live lab had lost the 2026-11-13 state: no U3 L1–L2 rules, empty PKI-CA/CA-RAIZ volumes (since 2026-11-17 22:09), half-made opt4 DMZ on vtnet0. GNS3 serves the project from `~/GNS3/projects/e96f2260-…/eval1.gns3`, not the repo copy. PKI rebuilt (new fingerprints) and rules rebuilt from scratch with the report numbering.
- L4 (2026-11-20): findings. OPNsense 26.7: the `openvpn` interface group only validates after `configctl interface invoke registration`; OpenVPN instances need `vpnid`; the issuing CA needs `caref` to the root or OpenVPN fails with "unable to get issuer certificate"; IDS rule download needs `configctl template reload OPNsense/IDS` first; homenet is comma separated. Kali has only SPICE, so the remote client is a container. Firefox on WEBTERM-ADMIN driven with xdotool on display :101 (keys need `--window`); console screenshots from a VTE window on a private Xtigervnc :102 (GDK_BACKEND=x11, WAYLAND_DISPLAY unset).
- L5 (2026-11-20): done. Remaining for the user: fix 7 report texts (U3_SUMATIVA.md §7), label Figure 1, attach the XML, copy the live `eval1.gns3` into the repo, commit.
- L6 (2026-11-20): "Yes, copy and commit". Live `eval1.gns3` copied; previous task committed as dd8853e on `feat/gns3-control-scripts`; this work as 7adfdce plus the docs commit on `feat/u3-sumativa`. Not pushed.
