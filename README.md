# Laboratorio GNS3 `eval1`

Red segmentada de una empresa logística (VLAN 10, 20, 30 y 99) con firewall OPNsense, VPN IPsec hacia un vehículo en ruta y SIEM Wazuh. Este archivo es el índice: dice qué documento abrir para cada tarea.

## Qué abrir según lo que necesitas

| Necesito… | Documento |
|---|---|
| Sumarme al proyecto: repositorio, laboratorio compartido, ramas y reglas | [docs/COLABORAR.md](docs/COLABORAR.md) |
| Un comando rápido para un nodo (consolas, pruebas, reinicios) | [docs/HOJA_TRAMPA.md](docs/HOJA_TRAMPA.md) |
| Entender la red: topología, direccionamiento, nodos, enlaces, switch | [docs/linea-base/INFRAESTRUCTURA.md](docs/linea-base/INFRAESTRUCTURA.md) — **línea base, no editar** (ver abajo) |
| Una contraseña o un usuario | [docs/linea-base/INFRAESTRUCTURA.md §10](docs/linea-base/INFRAESTRUCTURA.md) |
| Reglas del firewall, túnel IPsec, `ISP-WAN`, red del vehículo | [docs/VPN_VEHICULO.md](docs/VPN_VEHICULO.md) |
| Wazuh: contenedores, imágenes, accesos y pruebas | [docs/WAZUH_DOCKER.md](docs/WAZUH_DOCKER.md) |
| Reinstalar o ajustar la imagen base de OPNsense | [docs/OPNSENSE_PLANTILLA.md](docs/OPNSENSE_PLANTILLA.md) |
| El informe de seguridad que guía las mejoras | [docs/informe.pdf](docs/informe.pdf) |
| La PKI: CA raíz y emisora, emitir o revocar certificados, CRL y OCSP | [docs/PKI.md](docs/PKI.md) |
| Las mejoras aplicadas (antes → después), cómo se comprobaron y las limitaciones del entorno virtualizado | [docs/INFORME_MEJORAS.md](docs/INFORME_MEJORAS.md) |
| Saber qué falta por hacer | [odd/tasks/informe-hardening.md](odd/tasks/informe-hardening.md) |
| La evaluación de la Unidad 3 (seguridad perimetral): direccionamiento, Matriz 1, reglas R-xx con sus pruebas y horario de despacho | [docs/U3_SEGURIDAD_PERIMETRAL.md](docs/U3_SEGURIDAD_PERIMETRAL.md) |
| El informe formativo de la Unidad 3 para entregar (Matriz 1, Tabla 2, evidencias E0–E6 con capturas) | [docs/INFORME_U3_FORMATIVO.md](docs/INFORME_U3_FORMATIVO.md) |
| Ese mismo informe en PDF con la plantilla INACAP: fuente LaTeX en `docs/latex/`; se compila con `tectonic INFORME_U3_FORMATIVO.tex` dentro de esa carpeta (el PDF no se sube a Git) | [docs/latex/INFORME_U3_FORMATIVO.tex](docs/latex/INFORME_U3_FORMATIVO.tex) |

## Estado del trabajo

| Trabajo | Estado | Bitácora |
|---|---|---|
| Extensión VPN al vehículo | Terminado (2026-11-11) | [odd/tasks/vpn-vehiculo.md](odd/tasks/vpn-vehiculo.md) |
| Wazuh en Docker | Terminado (2026-11-11) | [odd/tasks/wazuh-docker.md](odd/tasks/wazuh-docker.md) |
| Endurecimiento según el informe | Fase 1 en curso: T1.1 y T1.2 terminadas (2026-11-13), PKI con CRL y OCSP; siguiente: T1.3. Resumen en [docs/INFORME_MEJORAS.md](docs/INFORME_MEJORAS.md) | [odd/tasks/informe-hardening.md](odd/tasks/informe-hardening.md) |
| Unidad 3, seguridad perimetral (entrega sumativa 16-11) | L0–L2 terminadas (2026-11-13): respaldo, reglas con registro y mínimo privilegio, denegación final R-99 y horario de despacho. Informe formativo con evidencias y capturas listo ([docs/INFORME_U3_FORMATIVO.md](docs/INFORME_U3_FORMATIVO.md)). Siguen L3 (VPN con certificados) y L4 (Suricata) | [odd/tasks/u3-perimetral.md](odd/tasks/u3-perimetral.md) |

## Línea base

[docs/linea-base/INFRAESTRUCTURA.md](docs/linea-base/INFRAESTRUCTURA.md) queda congelado como el estado **anterior** a las mejoras de seguridad. Sirve para reportar el antes y el después, así que no se corrige aunque difiera de la topología actual (por ejemplo, aún lista `CiscoRTT-1`, la VM `SIEM` y `Mint-1`). El estado posterior se documentará en `docs/INFRAESTRUCTURA_ACTUAL.md`. La lista real de nodos y enlaces está en `eval1.gns3`.

## Carpetas

| Ruta | Contenido | Idioma |
|---|---|---|
| `eval1.gns3` | Topología de GNS3. La escribe GNS3; no editar a mano | — |
| `docs/` | Documentación del laboratorio y el informe | Español |
| `docs/img/` | Capturas, una carpeta por documento | — |
| `configs/` | Configuraciones capturadas de router, switch e ISP | — |
| `configs/opnsense/` | Scripts PHP con los que se configuran los firewalls | — |
| `configs/backup-pre-hardening/` | Respaldo previo al endurecimiento (2026-11-13): `eval1.gns3`, `config.xml` de los dos OPNsense (solo en local, tienen claves privadas), running-config del switch y de `ISP-WAN`. También `eval1.gns3.pre-hardening` en la raíz | — |
| `docker/` | Dockerfiles de las imágenes propias (`odoo-gns3`, `wazuh-gns3`, `pki-ca-gns3`) | — |
| `.gitignore` | Qué no se sube a GitHub y por qué; detalle en [docs/COLABORAR.md](docs/COLABORAR.md) | — |
| `odd/tasks/` | Especificación y bitácora de cada trabajo | Inglés |
| `project-files/` | Discos y datos de los nodos. Los administra GNS3; no tocar | — |

## Reglas para mantener el orden

- Un documento nuevo va en `docs/` y recibe una fila en la primera tabla de este archivo.
- Sus capturas van en `docs/img/<nombre-del-documento>/`.
- Desde `docs/`, los enlaces a otras carpetas empiezan con `../` (por ejemplo `../configs/opnsense/`).
- Un trabajo nuevo recibe un archivo en `odd/tasks/` y una fila en "Estado del trabajo".
