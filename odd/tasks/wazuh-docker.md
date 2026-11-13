# Wazuh to Docker (eval1)

Objective: replace the `SIEM` QEMU VM (Ubuntu 22.04 + Wazuh all-in-one) with the official Wazuh 4.14.10 containers running as GNS3 Docker nodes on VLAN 30. No git repository: work units are not committed. Test runner: none (functional checks on the live lab).

## Specs

- S1. "También quiero migrar wazuh a docker".
- S2. Three GNS3 Docker nodes from derived images of `wazuh/wazuh-{indexer,manager,dashboard}:4.14.10` (configs and certificates baked in, like `odoo-gns3`), connected to `CiscoSW-1` VLAN 30 access ports.
- S3. The manager keeps 192.168.30.5, so the existing syslog target, agent enrollment address and the `SIEM_WAZUH` alias stay valid. Indexer 192.168.30.6, dashboard 192.168.30.7 (HTTPS 5601).
- S4. Dashboard login keeps the documented `admin` password (INFRAESTRUCTURA.md §10).
- S5. Data survives a GNS3 project close/reopen (GNS3 `extra_volumes`).
- S6. Docs (INFRAESTRUCTURA.md, HOJA_TRAMPA.md, VPN_VEHICULO.md firewall alias) updated.

Assumptions (defaults, not asked): the `SIEM` VM is stopped and unlinked, not deleted; no data is migrated from it (it was powered off and no agents were enrolled in any documented step); `SIEM_WAZUH` alias on `FW-ZONA-CENTRO` grows to .5–.7.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2 | inline | done | Images pulled, upstream configs in docker/wazuh-gns3/upstream |
| T2 | S2, S3, S4 | inline | done | certs.yml (IP-only SAN), 3 images via build.sh |
| T3 | S2, S3, S5 | inline | done | Nodes on Gi2/1-2/3, static IPs; SIEM VM link removed |
| T4 | S3, S4, S5 | inline | done | Indexer green with admin; filebeat OK; dashboard login 200; FW syslog → rule 5710; alias .5-.7 in pf; data kept after container recreate |
| T5 | S6 | inline | done | WAZUH_DOCKER.md, INFRAESTRUCTURA.md, HOJA_TRAMPA.md, VPN_VEHICULO.md, fw-zona-centro.php |

## Log

- L1 (2026-11-11): "También quiero migrar wazuh a docker".
- L2 (2026-11-11): implemented and verified. Gotchas: nologin users (indexer, dashboard), COPY --chmod=400 also applies to the directory it creates, GNS3 keeps the container across stop/start and only recreates it when a property such as extra_hosts changes (not image), serial console line limit needs chunked base64. Not tested: access from VLAN 99, real agent enrollment.
