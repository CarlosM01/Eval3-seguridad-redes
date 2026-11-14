# Unidad 3: seguridad perimetral (CI3062, Caso 2)

Este documento reúne el material de laboratorio del informe sumativo de la Unidad 3: direccionamiento, Matriz 1 de flujos, tabla de reglas con sus pruebas y el horario de despacho. Usa los códigos de la guía: **F-xx** (flujo), **R-xx** (regla), **E-xx** (evento) y **P-xx** (perfil). Continúa el informe de la Unidad 2 ([informe.pdf](informe.pdf)): los canales C1–C5 pasan a ser flujos, y los riesgos R1–R8 de esa unidad se citan como "riesgo U2".

> Última actualización: 2026-11-13 (viernes, noche), tras las tareas L0–L2 del plan de trabajo. Las horas son de Chile (UTC−3); el firewall registra en UTC.

| Tarea del plan | Estado |
|---|---|
| L0 Respaldo de los dos OPNsense | Hecho: `/conf/backup/config-pre-u3.xml` en cada firewall |
| L1 Log por regla, mínimo privilegio y denegación final | Hecho y probado (§4) |
| L2 Regla con horario y alias | Hecho y probado (§5) |
| L3 VPN con certificados, L4 Suricata, L5–L6 eventos y respaldo, L7 VPN remota | Pendientes |

---

## 1. Direccionamiento

| Segmento / VLAN | Zona | Red / máscara | Gateway | Dispositivos activos |
|---|---|---|---|---|
| VLAN 10 `ADMIN_LOGISTICA` | Interna: usuarios y bodega | 192.168.10.0/24 | 192.168.10.1 | `alpine-1` (DHCP, .230) |
| VLAN 20 `INVENTARIO_BODEGAS` | Interna crítica: inventario y WMS | 192.168.20.0/24 | 192.168.20.1 | `postgres-1` (.4), `odoo-1` WMS (.5) |
| VLAN 30 `SERVIDORES_SIEM` | Monitoreo | 192.168.30.0/24 | 192.168.30.1 | `WAZUH-MANAGER` (.5), `WAZUH-INDEXER` (.6), `WAZUH-DASHBOARD` (.7) |
| VLAN 99 `MGMT_TI` | Administración TI | 10.99.0.0/25 | 10.99.0.1 | Switch (.2), `PKI-CA` (.10), `WEBTERM-ADMIN` (.20), `SoporteTI` |
| WAN sede | Externa | 203.0.113.0/30 | 203.0.113.1 (`ISP-WAN`) | `FW-ZONA-CENTRO` (.2, IP pública fija) |
| Red móvil del operador | Externa (CGNAT) | 100.64.20.0/24 | 100.64.20.1 | `FW-VEHICULO-01` (DHCP, .10); sale a la sede como 198.51.100.10 |
| LAN del vehículo | Remota, por VPN | 172.16.50.0/24 | 172.16.50.1 | `TABLET-CONDUCTOR` (.10), `WEBTERM-VEHICULO` (.20) |
| Sin red | CA raíz fuera de línea | — | — | `CA-RAIZ` |

Puntos de control: el **firewall** `FW-ZONA-CENTRO` enruta y filtra todo el tráfico entre VLAN y hacia la WAN; el **concentrador VPN** es el mismo firewall (IPsec IKEv2, interfaz `enc0`); el **IPS** irá en ese firewall (Suricata, tarea L4) y el **SIEM** Wazuh recibe su syslog en la VLAN 30.

---

## 2. Matriz 1: flujos y puntos de control

Exposición: A/M/B (alta, media, baja). Riesgo: dimensión C/I/D afectada.

| ID | Origen | Destino | Servicio / puerto | Activo crítico (U2) | Exposición | Riesgo | Punto de control | Importancia del control |
|---|---|---|---|---|---|---|---|---|
| F-01 | VLAN 10 (bodega) | WMS `odoo-1` (VLAN 20) | TCP 8069 (443 tras T2.2) | ACT-01, ACT-02 (C1) | M | I, C | Firewall entre VLAN | Solo el puerto del WMS llega a la VLAN crítica; la base de datos queda fuera (riesgo U2 R2, R7) |
| F-02 | Vehículo y tablet del conductor | WMS `odoo-1` | IPsec + TCP 8069 | ACT-03, ACT-01 (C2) | A | C, I | Concentrador VPN + firewall `enc0` + horario | Red pública: el túnel cifra y la regla deja solo el WMS y solo en el turno (R1, R3) |
| F-03 | Supervisores y gerencia remotos | WMS | IPsec + HTTPS | ACT-01 (C4) | A | C | Concentrador VPN | Acceso remoto por perfil (R1, R3). Sin implementar: tarea opcional L7 |
| F-04 | VLAN 99 (administración TI) | Equipos de red y servidores | SSH 22, HTTPS 443, SNMP 161 | ACT-07 | M | C, I, D | Firewall + gestión solo en VLAN 99 | Quien administra controla la red; solo la VLAN 99 entra (condición 2 del caso) |
| F-05 | Socios B2B (externo) | Pasarela AS2 | HTTPS 443 | ACT-04 (C3a) | A | I | Firewall (publicación) | Sin regla: la pasarela no está montada (T5.3). Hoy la WAN no publica ningún servicio, así que el flujo está bloqueado por R-99 |
| F-06 | Firewall y servidores | SIEM Wazuh (VLAN 30) | Syslog UDP 514, agente 1514/1515 | ACT-06 (C5) | B | I, D | Firewall entre VLAN | Sin eventos no hay detección (condición 3 del caso, R5) |
| F-07 | VLAN 10 | Internet | DNS 53, NTP 123, HTTPS 443 | ACT-02 | A | C, I | Firewall + NAT de salida | Salida mínima; HTTP sin cifrar queda bloqueado (malware, fuga) |
| F-08 | VLAN 10, 20 y 30 | CA emisora `PKI-CA` (VLAN 99) | HTTP 80 (CRL), 2560 (OCSP) | ACT-05 | B | I | Firewall entre VLAN | Validar revocación sin abrir el resto de la VLAN 99 (R3) |

---

## 3. Reglas de `FW-ZONA-CENTRO`

Todas las reglas tienen el registro activado (*Log*). El firewall tiene estado, así que las respuestas no necesitan regla. B = básica (origen, destino y servicio); A = avanzada (alias, horario, denegación final con registro).

| ID | Flujo | Interfaz | Origen | Destino | Servicio | Acción | Tipo | Justificación | Prueba de validación (2026-11-13) |
|---|---|---|---|---|---|---|---|---|---|
| R-01 | F-01 | VLAN 10 | VLAN 10 | `WMS_ODOO` (192.168.20.5) | `PUERTOS_WMS` (TCP 8069) | Permitir + log | A (alias) | Usuarios de bodega solo al WMS | `alpine-1` → `http://192.168.20.5:8069/web/login`: **303** ✅ |
| R-02 | F-02 | IPsec | `RED_VEHICULO` (172.16.50.0/24) | `WMS_ODOO` | `PUERTOS_WMS` | Permitir + log, horario `HORARIO_DESPACHO` | A (horario, alias) | El conductor usa el WMS solo en el turno de despacho | Fuera de horario (21:10): tablet → Odoo **sin respuesta**, bloqueo con la etiqueta de R-99 ✅. Con el horario abierto a todo el día (prueba): **303** ✅. Horario restaurado |
| R-03 | F-04 | VLAN 99 | VLAN 99 | VLAN 10/20/30 (`REDES_VLAN_10_20_30`) y `SIEM_WAZUH` | SSH, SNMP, HTTPS, ICMP; puertos de Wazuh | Permitir + log | A (alias) | Solo la VLAN 99 administra | `PKI-CA` → `https://10.99.0.1`: **200**; → Wazuh 5601: **302**; → switch 22: **abierto** ✅ |
| R-04 | F-06 | VLAN 10, VLAN 20 | Su VLAN | `SIEM_WAZUH` | 514, 1514, 1515 | Permitir + log | B | Envío de eventos al SIEM | Sin cambios respecto de la U2 (pendiente prueba con agente) |
| R-05 | F-07 | VLAN 10 | VLAN 10 | No RFC 1918 | `PUERTOS_SALIDA_V10` (53, 123, 443) e ICMP | Permitir + log | A (alias, negación) | Salida a Internet sin HTTP 80 | `alpine-1` → `https://example.com`: **200** ✅; → `http://example.com`: **bloqueado** ✅ (antes del cambio: 200) |
| R-06 | F-02 | WAN | Cualquiera | IP WAN | UDP 500, UDP 4500, ESP | Permitir + log | B | El vehículo llega desde una IP variable (CGNAT) | Túnel `ESTABLISHED`, 2 Child SA `INSTALLED` tras el cambio ✅ |
| R-08 | F-08 | VLAN 10, 20, 30 | Su VLAN | `PKI_CA` | `PUERTOS_PKI` (80, 2560) | Permitir + log | A (alias) | CRL y OCSP | Sin cambios (probada en T1.2) |
| R-09 | — | VLAN 30 | VLAN 30 | Su gateway | DNS, NTP | Permitir + log | B | Servicios básicos del SIEM | Sin cambios |
| R-99 | Todos | Las 6 interfaces | Cualquiera | Cualquiera | Todo | **Bloquear + log** | A (denegación final) | Mínimo privilegio: lo no permitido queda registrado con su regla | `alpine-1` → `postgres-1:5432`: **bloqueado** ✅; tablet → VLAN 10: **100 % de pérdida** ✅ |

R-07 queda reservada para la VPN de acceso remoto (F-03, tarea opcional L7).

**Reglas deshabilitadas por mínimo privilegio** (quedan en la configuración, desactivadas): VLAN 10 → vehículo (antigua regla 10) y vehículo → VLAN 10 (antigua 200). El informe de la U2 limita el canal C2 al WMS. El Child SA 192.168.10.0/24 ↔ 172.16.50.0/24 sigue negociado; se elimina al rehacer la VPN con certificados (L3, T2.1).

Script: [../configs/opnsense/u3-reglas.php](../configs/opnsense/u3-reglas.php) (idempotente). Después de ejecutarlo: `configctl filter reload` y `configctl filter refresh_aliases`.

---

## 4. Cómo ver el registro de una regla

En la GUI: *Firewall → Log Files → Live View*, filtrando por la descripción (`R-01`, `R-99`, etc.). Por consola (opción 8):

```
grep filterlog /var/log/filter/latest.log | tail      # una línea por paquete registrado
pfctl -sr | grep -c log                               # reglas cargadas con registro
```

Cada línea de `filterlog` trae fecha y hora (UTC), la etiqueta de la regla, la interfaz, la acción, el origen, el destino y los puertos. Dos líneas reales del 2026-11-14 00:10 UTC (13-11, 21:10 en Chile), sin el encabezado syslog:

```
# Bloqueo: la tablet intenta el WMS fuera de horario (etiqueta 1d7cd239… = R-99 en IPsec)
139,,,1d7cd239-b850-4296-bef5-dfb953d13643,enc0,match,block,in,4,0x0,,63,16694,0,DF,6,tcp,60,172.16.50.10,192.168.20.5,56294,8069,0,S,...
# Permitido: alpine-1 sale por HTTPS (etiqueta 231aa18b… = R-05)
94,,,231aa18b-1ca2-421f-9ecc-59da8a840dcc,vlan010,match,pass,in,4,0x0,,64,16901,0,DF,6,tcp,60,192.168.10.230,172.66.147.243,56896,443,0,S,...
```

Las etiquetas cambian si se borra y se vuelve a crear la regla. Para relacionar una etiqueta con su R-xx: `pfctl -sr | grep <etiqueta>` o la columna *Rule* del Live View, que muestra la descripción.

El firewall envía su syslog a Wazuh (192.168.30.5, UDP 514). Wazuh trae reglas para `filterlog`, pero solo alerta sobre bloqueos repetidos desde un mismo origen (regla 87702, nivel 10). Los accesos permitidos se registran en el firewall, pero no generan alerta en Wazuh. Para el evento permitido E-01 en el dashboard hace falta una regla local en Wazuh (tarea L5).

---

## 5. Horario de despacho (R-02)

| Campo | Valor |
|---|---|
| Nombre | `HORARIO_DESPACHO` (*Firewall → Settings → Schedules*) |
| Días | Lunes a sábado |
| Horas | 10:00–23:00 UTC = **07:00–20:00 en Chile** (UTC−3, horario de verano) |
| Reevaluación | OPNsense crea una tarea cron que recarga el filtro en los minutos 1, 16, 31 y 46 de cada hora. Fuera de horario, la regla se desactiva y se cortan sus conexiones abiertas |

El firewall usa la hora UTC, así que el horario se escribe en UTC. Si Chile vuelve al horario de invierno (UTC−4), las horas en UTC deben pasar a 11:00–24:00. Para probar el lado permitido fuera de horario: `php /tmp/opn/u3-horario-prueba.php 00:00-23:59`, `configctl filter reload`, prueba, y después `php /tmp/opn/u3-horario-prueba.php 10:00-23:00` y otra vez `configctl filter reload`.
