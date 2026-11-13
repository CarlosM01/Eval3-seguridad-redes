# eval1 — instructions for agents

GNS3 lab of a segmented logistics network. Start with `README.md` (Spanish index of every document).

## Frozen baseline — do not edit

`docs/linea-base/INFRAESTRUCTURA.md` is the infrastructure **baseline**: the lab as it was **before** the security hardening from `docs/informe.pdf`. The owner keeps it unchanged to report improvements as "before → after".

- Never edit, move, rename or "fix" it, even when it disagrees with the live lab. It is outdated on purpose (it still lists `CiscoRTT-1`, the `SIEM` VM and `Mint-1`).
- Read it only as history or for the "before" side of a comparison.
- Current state: `eval1.gns3` is the source of truth for nodes and links. Write the post-hardening infrastructure in `docs/INFRAESTRUCTURA_ACTUAL.md` (create it when needed, add it to `README.md`).
- When a task or older note says "update `INFRAESTRUCTURA.md`", write to `docs/INFRAESTRUCTURA_ACTUAL.md` instead.

## Improvement report — keep it current

`docs/INFORME_MEJORAS.md` (Spanish) is the running report for the team, classmates and the professor. After every task that changes the lab, in the same turn:

- add the change to §2 (before → after, report requirement, task) and a §3 subsection (what, how verified, observations);
- add or update §4 rows for every environment limitation or deviation from `docs/informe.pdf`; move rows from "Prevista" to "Confirmada" when observed;
- update §1, §5 and the "Última actualización" line, and the "Estado del trabajo" row in `README.md`.

## Git and collaborators

The project is a Git repository shared with classmates (`docs/COLABORAR.md`). Work on a branch per task, use Conventional Commits, and before staging check that no private key material is included (`git grep --cached -I -E 'PRIVATE KEY|<prv>'`). Never commit `project-files/`, `*config.xml`, `docker/wazuh-gns3/certs/` or `docs/informe.pdf` (see `.gitignore`).

## Where things go

- Lab docs: `docs/`, in Spanish. Each new doc gets a row in `README.md`; its screenshots go in `docs/img/<doc-name>/`.
- Work specs and logs: `odd/tasks/`, in English. Active plan: `odd/tasks/informe-hardening.md`.
- Never edit `eval1.gns3` or `project-files/` by hand; GNS3 owns them.
- Credentials are lab values recorded in plain text on purpose (baseline §10, `docs/VPN_VEHICULO.md` §7).
