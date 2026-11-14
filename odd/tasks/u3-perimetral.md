# Unit 3 summative: perimeter security (CI3062, case 2)

Objective: make the lab produce every piece of evidence the Unit 3 guide (`INSTRUCCIONES_U3_FASE1EF_1.pdf`, not in the repo) asks for in its summative phase: rules with source, destination, service, action and a test; advanced rules (objects, schedules, default deny, per-rule logging); a working VPN; permitted, blocked and VPN-session events tied to a rule; an exported backup; access profiles linked to rules. It continues the Unit 2 report (`docs/informe.pdf`) and overlaps with `informe-hardening.md` (T2.1, T2.3, T4.1, T5.2). Deliverable due 2026-11-16 23:00 (date not yet confirmed by the teacher; 2026-11-18 possible). Team work plan (Spanish, shared doc): "Plan de trabajo U3 – Seguridad perimetral (Caso 2)".

## Specs

- S1. Guide step 3: basic and advanced firewall rules, each with a validation test; VPN parameters and tunnel evidence.
- S2. Guide step 4: logging of firewall and VPN events, sent to the SIEM; at least E-01 permitted, E-02 blocked, E-03 VPN session, each linked to an R-xx; exported config backup; operational check.
- S3. Guide step 5: at least 3 profiles P-xx linked to R-xx, least privilege, one restriction proven in the lab.
- S4. Traceability codes F-xx / R-xx / E-xx / P-xx kept across the whole work.
- S5. Documentation as files for Carlos to commit ("déjame la documentación como archivos para que después él la suba", 2026-11-13).

Assumptions (defaults, not asked): dispatch schedule Monday–Saturday 07:00–20:00 Chile time (proposed in chat, not confirmed); the firewall clock stays UTC, so the schedule is written as 10:00–23:00 UTC.

## Tasks

| ID | Specs | Status | Evidence |
|---|---|---|---|
| L0 | S2 | done (2026-11-13) | `/conf/backup/config-pre-u3.xml` on both OPNsense (centre sha256 `d76201e7…`, vehicle `94d68c60…`) |
| L1 | S1, S2 | done (2026-11-13) | `configs/opnsense/u3-reglas.php`: log on every rule, R-xx prefixes, rules 10 and 200 disabled, rule 30 without port 80, rule 9000 block+log on 6 interfaces. Tests in `docs/U3_SEGURIDAD_PERIMETRAL.md` §3 |
| L2 | S1 | done (2026-11-13) | Schedule `HORARIO_DESPACHO` on rule 210, aliases `WMS_ODOO`, `PUERTOS_WMS`, `PUERTOS_SALIDA_V10`; cron "reload filter for configured schedules" at minutes 1,16,31,46; allowed side tested with `u3-horario-prueba.php` |
| L3 | S1 | pending | = hardening T2.1 (certificates, ChaCha20-Poly1305, X25519, MOBIKE); remove the VLAN 10 Child SA |
| L4 | S1, S2 | pending | = hardening T4.1 (Suricata, raise the node to 4 GB) |
| L5 | S2 | pending | E-01..E-04 captured; Wazuh local rule so permitted `filterlog` events raise an alert (built-in rules only alert on rule 87702) |
| L6 | S2 | pending | exported backup and operational check |
| L7 | S3 | optional | = hardening T5.2 (remote access VPN, profiles supervisor/admin) |

## Log

- L1 (2026-11-13): user shared the Unit 3 guide, the case 2 statement and the Unit 2 report, and asked that the lab meet them ("neceito que cumpla con estas cosas"). Gap analysis given in chat; work plan written as a shared doc.
- L2 (2026-11-13): "si procede con todas las tareas que se entregan el viernes en la noche, nadie esta usando el lab". L0–L2 done through the serial console (scripts uploaded as gzip+base64 lines ≤ 140 chars, sha256 checked). Findings: OPNsense 26.7 MVC rules take the legacy schedule by name (`sched`, `ScheduleField`); `<timerange><position>` uses 1 = Monday … 7 = Sunday; out-of-window rules are disabled at filter reload and their states killed; `configctl cron restart` was needed to install the 15-minute reevaluation cron. The `WAZUH-MANAGER` telnet console gave no prompt (two attempts timed out); the indexer API from `PKI-CA` was used instead. Nothing committed: the repo is read-only from this session; files handed to the user.
