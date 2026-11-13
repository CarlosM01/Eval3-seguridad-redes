# VPN vehicle extension (eval1)

Objective: connect a vehicle LAN on a simulated mobile network to the central site through an IPsec tunnel across a simulated WAN, with OPNsense at both ends. No git repository: work units are not committed. Test runner: none (functional checks on the live lab).

## Specs

- S1. "conectar dos redes lan independientes mediante vpn en un tunel a través de la red wan" — the remote LAN is "algunos dispositivos conectados mediante una red movil o pública. por ejemplo un conductor en ruta".
- S2. Central border: OPNsense `FW-ZONA-CENTRO` replaces `RTR-ZONA-CENTRO` (trunk to `SW-ZONA-CENTRO` Gi0/0, VLANs 10/20/30/99, WAN 203.0.113.2/30). Keeps the router's DHCP, NAT only for VLAN 10, syslog to 192.168.30.5 and ACL criteria 1–5.
- S3. `ISP-WAN` (IOSv): 203.0.113.1/30 to the central site, carrier segment 100.64.20.0/24 with DHCP for the vehicle, CGNAT emulation (vehicle traffic to the central site leaves as 198.51.100.10), Internet through NAT1.
- S4. Vehicle router: "OPNsense" (user decision, 2026-11-11) → `FW-VEHICULO-01`, LAN 172.16.50.0/24, WAN by DHCP from `ISP-WAN`.
- S5. IPsec IKEv2, PSK, FQDN identities, the vehicle initiates, the central site only responds to any remote address; NAT-T, DPD.
- S6. Documentation updated with screenshots ("cuando termines actualiza la documentación, incluye las capturas de pantalla").

Assumptions (defaults, not asked): `RTR-ZONA-CENTRO` is disconnected, not deleted; the tunnel covers 172.16.50.0/24 ↔ 192.168.10.0/24; vehicle client `TABLET-CONDUCTOR` (alpine docker) 172.16.50.10.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2, S4 | inline | done | Template `OPNsense-26.7` created via API (console telnet); base image chmod 444 |
| T2 | S1–S4 | inline | done | 4 nodes added, RTR links removed, 5 links added (eval1.gns3 auto-saved) |
| T3 | S3 | inline | done | `ping 1.1.1.1` OK; NVI translation 198.51.100.10:500 observed |
| T4 | S2 | inline | done | VLAN ifaces up, alpine-1 DHCP 192.168.10.230, Internet OK |
| T5 | S4, S5 | inline | done | IKE_SA ESTABLISHED, CHILD_SA INSTALLED TUNNEL-in-UDP |
| T6 | S1, S5, S6 | inline | done | ping both ways 0% loss; VPN_VEHICULO.md + screenshots; VLAN 20/30/99 rules not traffic-tested |

## Log

- L1 (2026-11-11): "Tengo que crear una pequeña extensión de la topología, una nueva red independiente, ya que el nuevo ejercicio es conectar dos redes lan independientes mediante vpn en un tunel a través de la red wan" (original request, earlier session).
- L2 (2026-11-11): "La red secundaria no debe ser la zona norte, sino algunos dispositivos conectados mediante una red movil o pública. por ejemplo un conductor en ruta".
- L3 (2026-11-11): vehicle router → "OPNsense".
- L4 (2026-11-11): implemented and verified. Deviations: OPNsense auto VPN rules are not generated for remote "any", so WAN rules 300-320 were added; `ipsec.enable` is a legacy field set by ipsec-enable.php; outbound NAT left automatic (criterion 4 enforced by filter rules). Next: user decision on odoo-1 (VLAN 10) to postgres-1 (VLAN 20) access.
- L5 (2026-11-11): user decision "Regla 5432 puntual". FW-ZONA-CENTRO rule 25: 192.168.10.4 → 192.168.20.4 TCP 5432. Static IPs odoo-1 .10.4 / postgres-1 .20.4 (+gateway). odoo-1 recreated on image odoo-gns3:latest (nologin shell fix) and moved to SW Gi0/3 (stale uBridge on Gi1/0 after failed delete). Verified: psycopg2 connect OK from odoo-1; alpine-1 → 5432 blocked.
