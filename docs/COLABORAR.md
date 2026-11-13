# Cómo colaborar en `eval1`

Guía para los compañeros que se suman al proyecto: qué hay en el repositorio, cómo entrar al laboratorio compartido y cómo trabajar sin pisarse.

## Inicio rápido

1. Pedir al dueño del proyecto acceso al repositorio de GitHub y a la red ZeroTier del curso.
2. Clonar el repositorio y leer [README.md](../README.md), que es el índice de toda la documentación.
3. Ver qué toca hacer en [../odd/tasks/informe-hardening.md](../odd/tasks/informe-hardening.md) y avisar al grupo qué tarea vas a tomar.
4. Conectarse al laboratorio compartido con el cliente GNS3 2.2 (sección 2).
5. Trabajar en una rama propia y abrir un pull request (sección 3).

---

## 1. Qué hay en el repositorio y qué no

El repositorio guarda la **documentación y lo necesario para reconstruir** el laboratorio. Los datos de ejecución quedan en el servidor.

| Está en el repo | Para qué |
|---|---|
| `docs/` | Documentación del laboratorio, en español |
| `configs/` | Configuraciones del switch y del ISP, y scripts PHP de OPNsense |
| `docker/` | Dockerfiles de las imágenes propias (`odoo-gns3`, `pki-ca-gns3`, `wazuh-gns3`) |
| `odd/tasks/` | Plan de trabajo y bitácora de cada tarea, en inglés |
| `eval1.gns3` | Topología de GNS3 (nodos y enlaces) |
| `CLAUDE.md` | Instrucciones para los agentes de IA que trabajan en el proyecto |

| **No** está en el repo (`.gitignore`) | Por qué | Cómo conseguirlo |
|---|---|---|
| `project-files/` | Discos y volúmenes de los nodos, unos 30 GB. Incluye los tokens y PIN de la PKI | Vive solo en el servidor |
| `docs/informe.pdf` | Documento del curso | Pedirlo al dueño o descargarlo del curso |
| `docker/wazuh-gns3/certs/` | Claves privadas de Wazuh | Generarlas con el comando de [WAZUH_DOCKER.md](WAZUH_DOCKER.md) |
| `configs/backup-pre-hardening/*_config.xml` | Incluyen la clave privada del certificado web y la API key de cada firewall | Copia local del dueño |
| Claves y certificados (`*.key`, `*-key.pem`, `*.p12`) | Nunca se publican | Se regeneran |

Las contraseñas del laboratorio (`test123` y otras) **sí** están en la documentación, a propósito: es un laboratorio de estudio y el profesor debe poder evaluarlo. No las reutilices en ningún sistema real.

## 2. El laboratorio compartido

Hay un solo laboratorio, en el servidor GNS3 del dueño. Todos trabajan sobre los mismos nodos.

| Dato | Valor |
|---|---|
| Servidor | `10.82.151.125:3080`, en la red ZeroTier del curso |
| Cliente | GNS3 **2.2** (misma versión menor que el servidor) |
| Proyecto | `eval1` |
| Consolas | `telnet 10.82.151.125 <puerto>`; tabla de puertos en [HOJA_TRAMPA.md](HOJA_TRAMPA.md) |
| Interfaz gráfica de administración | VNC `10.82.151.125:5902` (`WEBTERM-ADMIN`) |

**Al arrancar:** los nodos no arrancan solos. Primero `CiscoSW-1`, `ISP-WAN`, `FW-ZONA-CENTRO` y `FW-VEHICULO-01`; unos 2 minutos después, el resto. `Kali` y `SoporteTI` quedan apagados salvo que la tarea los use.

**Cuidado, el servidor no tiene contraseña:** cualquiera en la red ZeroTier puede parar o borrar nodos y abrir consolas con root. Ver [INFORME_MEJORAS.md §4.2](INFORME_MEJORAS.md).

- No borres ni recrees nodos sin avisar al grupo.
- No dejes consolas abiertas con sesión de root (cerrar con `exit`).
- Antes de cambiar reglas del firewall, respalda `config.xml` (ver la tarea en el plan).

## 3. Cómo trabajar con Git

| Regla | Detalle |
|---|---|
| Una rama por tarea | `git switch -c t1.3-certificados-firewall`, con el ID de la tarea del plan |
| Commits | [Conventional Commits](https://www.conventionalcommits.org/es/): `docs: …`, `feat: …`, `fix: …` |
| Integración | Pull request a `main`; otra persona lo revisa antes de unir |
| `eval1.gns3` | Lo escribe GNS3, no se edita a mano. Solo lo sube quien cambió la topología en esa tarea, y una sola tarea de topología a la vez: Git no puede mezclar dos versiones de este archivo |

Antes de abrir el pull request, en la misma rama:

- [ ] Las comprobaciones de la tarea pasaron en el laboratorio y quedaron anotadas en `odd/tasks/informe-hardening.md` (tabla de resultados y `## Log`).
- [ ] [INFORME_MEJORAS.md](INFORME_MEJORAS.md) tiene la fila antes → después, la subsección de la tarea y las limitaciones nuevas.
- [ ] Los documentos afectados están al día ([HOJA_TRAMPA.md](HOJA_TRAMPA.md), [PKI.md](PKI.md), [VPN_VEHICULO.md](VPN_VEHICULO.md)…).
- [ ] Ningún archivo con claves privadas (`git diff --cached --stat` y revisar).

## 4. Reglas del proyecto

- [linea-base/INFRAESTRUCTURA.md](linea-base/INFRAESTRUCTURA.md) es la **línea base congelada** (el "antes"). No se edita aunque esté desactualizada.
- La documentación del laboratorio va en `docs/`, en español. Cada documento nuevo lleva una fila en [README.md](../README.md) y sus capturas van en `docs/img/<documento>/`.
- El plan y las bitácoras van en `odd/tasks/`, en inglés.
- Los firewalls se configuran con scripts PHP en `configs/opnsense/`, nunca editando `config.xml` a mano.
- Para reconstruir una imagen Docker: `docker build -t <imagen> docker/<imagen>/`. Después hay que forzar la recreación del nodo, como explica [HOJA_TRAMPA.md](HOJA_TRAMPA.md).
