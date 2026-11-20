# Evaluación sumativa U3: cambios en el laboratorio y evidencias

Este documento acompaña al informe `Informe_Evaluacion3_Sumativa_SeguridadRedes_ZonaCentro.docx` (versión con la retroalimentación del profesor). Indica qué se cambió en GNS3 para que el laboratorio cumpla lo que dice el informe, los resultados de las pruebas P-01 a P-08 y de los eventos E-01 a E-04, y qué captura pegar en cada figura.

- **Fecha de la ejecución:** 2026-11-20, entre las 18:30 y las 19:30 de Chile (21:30–22:30 UTC). Los equipos registran en UTC; Chile está en UTC−3.
- **Capturas:** [img/u3-sumativa/](img/u3-sumativa/). Las de la GUI se tomaron desde `WEBTERM-ADMIN` (VLAN 99). Las de consola son la consola GNS3 (telnet) de cada nodo.
- **Bitácora técnica (inglés):** [../odd/tasks/u3-sumativa.md](../odd/tasks/u3-sumativa.md).

> **Antes de entregar, revisar §7:** hay siete puntos en los que el texto del informe no coincide con lo que se pudo implementar y conviene corregir el Word.

## 1. Qué se cambió en el laboratorio

| Componente | Cambio | Script o archivo |
|---|---|---|
| Topología GNS3 | Nodo nuevo `B2B-DMZ` (Docker `b2b-dmz-gns3`, nginx HTTPS) en `CiscoSW-1` Gi1/3. Nodo nuevo `REMOTO-SUPERVISOR` (Docker `remoto-gns3`: OpenVPN, nmap, curl) en `ISP-WAN` Gi0/3, red externa 192.0.2.0/24. `FW-ZONA-CENTRO` pasa de 2 a 4 GB de RAM (Suricata) | [../docker/b2b-dmz-gns3/](../docker/b2b-dmz-gns3/), [../docker/remoto-gns3/](../docker/remoto-gns3/) |
| `CiscoSW-1` | VLAN 40 `DMZ_B2B`; Gi1/3 en acceso VLAN 40; troncal Gi0/0 restringido a `10,20,30,40,99`. Se quitaron las SVI de las VLAN 10/20/30 (el switch respondía SSH desde la VLAN 10) y las líneas VTY solo aceptan 10.99.0.0/25 (ACL `MGMT_VTY`) | [../configs/backup-u3-sumativa/CiscoSW-1_running-config_20261120.txt](../configs/backup-u3-sumativa/CiscoSW-1_running-config_20261120.txt) |
| `ISP-WAN` | Gi0/3 192.0.2.1/24: "Internet" simulada para el supervisor remoto y el atacante externo | [../configs/ISP-WAN_running-config.txt](../configs/ISP-WAN_running-config.txt) |
| PKI | Reconstruida (ver §8). Certificados emitidos con CSR del propio equipo: `fw-centro.empresa.local` y `vehiculo-01.empresa.local` (equipo, ECDSA P-256), `as2.empresa.local` (socio, RSA-3072, SAN 203.0.113.2 y 192.168.40.10), `supervisor1@supervisores.empresa.local` (usuario, ECDSA P-256) | [PKI.md](PKI.md) |
| `FW-ZONA-CENTRO`: interfaces | `opt4` DMZ = `vlan040` 192.168.40.1/24; `opt5` TRUNK = `vtnet0` sin IP (solo para que Suricata use la interfaz padre); `ovpns1` (OpenVPN) | [../configs/opnsense/u3-sumativa-centro.php](../configs/opnsense/u3-sumativa-centro.php) |
| `FW-ZONA-CENTRO`: reglas | Tabla R-01 a R-99 del informe reconstruida desde cero: todas con registro, R-99 en las 8 interfaces, alias del informe, horario `HORARIO_DESPACHO` en R-02 | `u3-sumativa-centro.php` |
| `FW-ZONA-CENTRO`: NAT | Destination NAT 203.0.113.2:443 → 192.168.40.10:443 (R-05A). Source NAT híbrido con regla explícita de la VLAN 10 (R-07) | `u3-sumativa-centro.php` |
| IPsec (ambos firewalls) | PSK → certificados X.509 de PKI-CA. Un solo Child SA 192.168.20.5/32 ↔ 172.16.50.0/24 (se borró el de la VLAN 10). IKE `aes256gcm16-sha256-ecp521`, ESP `aes256gcm16-ecp521` | [../configs/opnsense/u3-ipsec-pki.php](../configs/opnsense/u3-ipsec-pki.php) |
| OpenVPN | Servidor `VPN-REMOTA-SUPERVISORES (F-03)` en 203.0.113.2 UDP 1194, `tun`, pool 10.8.0.0/24, AES-256-GCM, TLS con certificados de PKI-CA (el cliente debe tener un certificado de la emisora), solo se empuja la ruta 192.168.20.5/32 | [../configs/opnsense/u3-openvpn.php](../configs/opnsense/u3-openvpn.php) |
| Suricata | IPS Netmap en `vtnet1` (WAN) y `vtnet0` (TRUNK). ET Open: `emerging-scan`, `emerging-exploit`, `emerging-web_server`. Una política convierte sus alertas en *drop*. Alertas a syslog y EVE | [../configs/opnsense/u3-suricata.php](../configs/opnsense/u3-suricata.php) |
| Syslog → Wazuh | Destino remoto UDP 192.168.30.5:514 (ya existía, se mantuvo); ahora también lleva Suricata y OpenVPN | GUI: System → Settings → Logging → Remote |
| Wazuh manager | Decodificador para el EVE de Suricata que llega por syslog y reglas locales 100200–100212 (E-01 a E-04) | [../docker/wazuh-gns3/manager/local/](../docker/wazuh-gns3/manager/local/) |

Respaldo previo de cada paso en el firewall: `/conf/backup/config-pre-u3-sumativa.xml`, `config-pre-u3-ipsec-pki.xml` y `config-pre-u3-suricata.xml`.

## 2. Pruebas de conectividad P-01 a P-08 (§4.4 del informe)

Todas pasaron. La columna "Estado" del informe puede decir **OK**.

| ID | Origen | Resultado observado | Captura |
|---|---|---|---|
| P-01 | `alpine-1` (VLAN 10) | `192.168.20.5:8069 open`, `HTTP/1.1 303 SEE OTHER` | `fig05-p01-p02-alpine-1.png` |
| P-02 | `alpine-1` | 5432: `Operation timed out` (R-01B); `http://example.com`: `download timed out`; `https://example.com`: descarga correcta | `fig05-p01-p02-alpine-1.png` |
| P-03 | `TABLET-CONDUCTOR` en horario | `192.168.20.5:8069 open`, `303 SEE OTHER` (21:55:56 y 22:01:17 UTC) | `fig05-p03-tablet-en-horario.png` |
| P-04 | `TABLET-CONDUCTOR` fuera de horario | 8069: `Operation timed out`; ping a 192.168.10.230: 100 % de pérdida | `fig05-p04-tablet-fuera-horario.png` |
| P-05 | `REMOTO-SUPERVISOR` con OpenVPN (10.8.0.2) | WMS `succeeded`, `HTTP 303`; `https://10.99.0.1`: código `000`, `rc=28` (R-03B); 5432: `timed out` (R-03B) | `fig05-p05-openvpn-supervisor.png` |
| P-06 | `REMOTO-SUPERVISOR` sin VPN (192.0.2.10) | `curl -kI https://203.0.113.2` → `HTTP/1.1 200 OK`, página "Pasarela B2B / EDI"; certificado `CN=as2.empresa.local` emitido por `Empresa CA Emisora` | `fig05-p06-nat-dmz.png` |
| P-07 | `B2B-DMZ` (192.168.40.10) | 8069 `succeeded` (`rc=0`); 5432 `timed out` (`rc=1`); 10.99.0.1:443 `timed out` | `fig05-p07-dmz.png` |
| P-08 | `WEBTERM-ADMIN` (VLAN 99) | `https://10.99.0.1` → `200`; `https://192.168.30.7:5601` → `200`; SSH `adminTI@10.99.0.2` correcto; ACL `MGMT_VTY` con 4 coincidencias | `fig05-p08-webterm-admin.png` |

Notas: para P-04 el horario se acortó un momento a 10:00–11:00 UTC y se restauró a 10:00–23:00 UTC (el mismo método que en la formativa, [../configs/opnsense/u3-horario-prueba.php](../configs/opnsense/u3-horario-prueba.php)). Para P-05, el cliente agregó a mano rutas hacia 10.99.0.0/25 y 192.168.20.4 por el túnel, para probar que el firewall bloquea aunque el usuario intente forzar el acceso. El servidor solo empuja la ruta del WMS.

## 3. Eventos E-01 a E-04 (§5.2 del informe)

Horas para la columna "Fecha y Hora Exacta". Los eventos se repitieron a las 22:13 UTC para la captura del Live View; cualquiera de las dos series sirve.

| ID | Fecha y hora (Chile / UTC) | Origen | Destino y servicio | Regla / alerta | Acción observada |
|---|---|---|---|---|---|
| E-01 | 20-11-2026 18:55:47 / 21:55:47 | 172.16.50.10 (`TABLET-CONDUCTOR`) | 192.168.20.5:8069 TCP | `filterlog` R-99 en `enc0` (IPsec); Wazuh 100210 (nivel 7) | BLOCK fuera de `HORARIO_DESPACHO` |
| E-02 | 20-11-2026 18:55:56–18:56:10 / 21:55:56–21:56:10; correlación 18:56:14 / 21:56:14 | 192.168.10.230 (`alpine-1`) | 192.168.20.4:5432 TCP | R-01B; Wazuh 100212 (nivel 6) por cada intento y **87702 (nivel 10)** por la repetición | BLOCK + alerta SIEM |
| E-03 | 20-11-2026 18:56:20–18:56:42 / 21:56:20–21:56:42 (primera serie 18:52:57 / 21:52:57) | 192.0.2.10 (`REMOTO-SUPERVISOR`, atacante externo) | 203.0.113.2 (WAN), puertos 1433, 1521, 3306, 5432, 5810 y sondas UDP | Suricata: `ET SCAN NMAP OS Detection Probe` (SID 2018489), `ET SCAN Suspicious inbound to PostgreSQL port 5432` (SID 2010939), `...mySQL port 3306` (2010937), `...MSSQL port 1433` (2010935), `...Oracle SQL port 1521` (2010936), `Potential VNC Scan 5800-5820` (2002910), `Potential SSH Scan` (2001219); Wazuh 100201 (nivel 10) | DROP en línea (`action: blocked`) en `vtnet1` |
| E-04 | 20-11-2026 18:56:08 / 21:56:08 (también 18:49:16 / 21:49:16) | 10.8.0.2 (cliente OpenVPN) | 10.99.0.1:443 HTTPS | `filterlog` R-03B en `ovpns1`; Wazuh 100211 (nivel 8) | BLOCK hacia la VLAN 99 |

Comandos usados: E-01 `nc -zv -w 4 192.168.20.5 8069` con el horario de prueba; E-02 doce `nc -z -w 1 192.168.20.4 5432` seguidos; E-03 `nmap -Pn -sS` y `nmap -Pn -O` contra 203.0.113.2; E-04 `curl -k -m 3 https://10.99.0.1`.

## 4. Respaldos (§5.3 del informe)

| Dispositivo | Archivo | Fecha y hora | Cómo |
|---|---|---|---|
| `FW-ZONA-CENTRO` | `config-FW-ZONA-CENTRO.empresa.local-20261120.xml` (120 KB) | 20-11-2026 19:18 Chile (22:18 UTC) | GUI → System → Configuration → Backups → *Download configuration* (Figura 8). Firefox lo guardó como `...-20261120221838.xml` |
| `FW-VEHICULO-01` | `config-FW-VEHICULO-01.empresa.local-20261120.xml` (47 KB) | 20-11-2026 19:19 Chile | Copia de `/conf/config.xml` por la consola serie |
| `CiscoSW-1` | `CiscoSW-1_running-config_20261120.txt` | 20-11-2026 19:27 Chile (después de quitar las SVI) | `show running-config` por consola |

Están en [../configs/backup-u3-sumativa/](../configs/backup-u3-sumativa/). Los dos XML **no se suben a Git** (`.gitignore`): contienen las claves privadas de los certificados y de la GUI. Para el AAI hay que adjuntar `config-FW-ZONA-CENTRO.empresa.local-20261120.xml` desde la copia local de Carlos.

## 5. Estado operativo (§5.4 del informe)

- Interfaces arriba: WAN `vtnet1`, TRUNK `vtnet0`, `vlan010`, `vlan020`, `vlan030`, `vlan040`, `vlan099`, `enc0`, `ovpns1` (Figura 2a).
- Servicios en ejecución: `configd`, `cron`, `dnsmasq`, `ntpd`, `openssh`, `openvpn`, `pf`, `strongswan`, `suricata`, `syslog-ng`, `unbound`, `webgui` (Figura 9c).
- Recursos (Lobby → Dashboard, 22:21 UTC): memoria 28 %, disco 13 %, carga 0,09.
- Wazuh: `wazuh-modulesd`, `monitord`, `logcollector`, `remoted`, `syscheckd`, `analysisd`, `execd`, `db`, `authd` y `apid` en ejecución (los que dicen "not running" no se usan en un manager único). Indexer `_cluster/health`: `"status" : "green"`, 1 nodo.

## 6. Capturas para cada figura del informe

| Figura del informe | Archivo(s) en `docs/img/u3-sumativa/` | Qué muestra |
|---|---|---|
| Figura 1: esquema de red en GNS3 | `fig01-topologia-gns3.png` | Lienzo GNS3 con `B2B-DMZ` en la zona DMZ y `REMOTO-SUPERVISOR` en "Internet No Confiable". Falta agregar sobre la imagen los recuadros de zonas y puntos de control PC-01 a PC-04 (paso 2 de la nota del informe) |
| Figura 2: interfaces y VLAN | `fig02a-opnsense-interfaces.png`, `fig02b-ciscosw1-vlan-trunk.png` | Interfaces → Overview (vlan040 DMZ 192.168.40.1/24, ovpns1 10.8.0.1) y `show vlan brief` / `show interfaces trunk` con la VLAN 40 permitida |
| Figura 3: reglas, alias, horario y NAT | `fig03a-reglas-vlan10.png` … `fig03f-reglas-wan.png`, `fig03g-nat-port-forward.png`, `fig03h-nat-salida.png`, `fig03i-horario.png`, `fig03j-alias.png` | Reglas por interfaz (VLAN 10, DMZ, IPsec, OpenVPN, VLAN 99, WAN) con su código R-xx, Destination NAT 443 → DMZ, Source NAT de la VLAN 10, `HORARIO_DESPACHO` y alias |
| Figura 4: túneles VPN | `fig04a-ipsec-status.png`, `fig04b-swanctl.png`, `fig04c-openvpn-status.png` | IPsec Phase 1 IKEv2 con `fw-centro.empresa.local` ↔ `vehiculo-01.empresa.local` y un solo Child SA `INSTALLED`; `swanctl` con *public key authentication* y `AES_GCM_16-256/.../ECP_521`; sesión OpenVPN de `supervisor1@supervisores.empresa.local` con IP 10.8.0.2 |
| Figura 5: pruebas P-01 a P-08 | `fig05-p01-p02-alpine-1.png` … `fig05-p08-webterm-admin.png` | Ver §2 |
| Figura 6: Suricata y syslog | `fig06a-ids-admin.png`, `fig06b-syslog-destino.png` | IDS *Enabled*, *Netmap (IPS)*, interfaces TRUNK y WAN, syslog y EVE activos; destino remoto UDP 192.168.30.5 |
| Figura 7: eventos | `fig07a-ids-alertas.png`, `fig07b-firewall-live-view.png`, `fig07c-wazuh-eventos-firewall.png`, `fig07d-wazuh-eventos-suricata.png` | Alertas de Suricata `blocked` (E-03); Live View con E-01 (IPsec R-99), E-02 (R-01B) y E-04 (R-03B); Wazuh con 100210/100211/100212/87702 y con 100201 |
| Figura 8: respaldo XML | `fig08-backup-xml.png` | Backups con la descarga `config-FW-ZONA-CENTRO...xml` completada (120 KB) |
| Figura 9: estado operativo | `fig09a-dashboard.png`, `fig09b-wazuh-status.png`, `fig09c-servicios.png` | Dashboard con widgets en verde, `wazuh-control status` + `_cluster/health` green, lista de servicios en ejecución |
| Figura 10: política por perfil | `fig10-p01-alpine-restricciones.png` y las de la Figura 5 | P-01: WMS sí, OPNsense y BD no; P-02: P-03/P-04; P-03: P-05; P-04: P-08; P-05: P-07 |

## 7. Diferencias entre el informe Word y el laboratorio

Corregir estos textos en el informe, o aceptar la diferencia explicándola:

1. **Grupo DH (§3.1, §4.3):** el informe dice "ECP-256". OPNsense 26.7 solo ofrece ECP-521 entre las curvas NIST para AES-GCM; el laboratorio usa `aes256gcm16-sha256-ecp521` (IKE) y `aes256gcm16-ecp521` (ESP). Es más fuerte, pero el texto debe decir ECP-521.
2. **Cliente remoto y atacante (§4.3, P-05, E-03, E-04):** el informe pone Kali como ejemplo. Se usó el contenedor `REMOTO-SUPERVISOR` (192.0.2.10), porque Kali solo tiene consola SPICE y no se pudo automatizar. Kali queda en el lienzo, apagado y sin enlace.
3. **Autenticación de P-03 (§6.1):** el informe dice "certificado personal + usuario/clave VPN". El laboratorio usa **solo el certificado**. Para tener también usuario y clave falta crear el usuario en OPNsense y activar *Local Database* en el servidor OpenVPN. La opción OCSP del servidor quedó marcada, pero el registro dice `no OCSP configured`: hoy la revocación de un certificado de cliente **no** corta la VPN. Falta cargar la URL OCSP (`http://10.99.0.10:2560`) en la CA de *System → Trust*; mientras tanto el informe no debe afirmar "revocación inmediata vía CRL/OCSP" para F-03.
4. **Respaldos "cifrados" (§5.3):** los XML se descargaron sin cifrar (opción *Encrypt this configuration file* desmarcada) y se guardan fuera de Git. Si se mantiene la palabra "cifrada", hay que volver a descargarlos con esa opción.
5. **Alias `SIEM_WAZUH` y R-04 (§4.2):** `SIEM_WAZUH` = solo 192.168.30.5, como dice el informe. R-04 permite además 9200 (indexer) y 55000 (API de Wazuh) desde la VLAN 99; el informe lista 443, 22, 161 y 5601.
6. **Reglas de la WAN:** además de R-05A, la WAN tiene R-02 (IKE 500, NAT-T 4500, ESP) y R-03A (OpenVPN UDP 1194). La VLAN 10 tiene un R-07 extra: DNS hacia el propio firewall. Conviene mencionarlas en la tabla.
7. **Eventos (§5.2):** usar las horas y firmas reales de §3. E-03 no fue contra 443/80, sino un escaneo nmap contra varios puertos de 203.0.113.2.

También cambió algo que el informe da por hecho: "CiscoSW-1 opera exclusivamente en capa 2" solo pasó a ser cierto hoy. Antes tenía SVI en las VLAN 10/20/30 y respondía SSH en 192.168.10.2. Puede agregarse como séptimo ajuste en la tabla 3.2 (componente: CiscoSW-1; antes: SVI en VLAN operativas; después: solo Vlan99 y ACL `MGMT_VTY`).

## 8. Incidencias encontradas al empezar

- **El laboratorio vivo había perdido cambios del 13-11.** Faltaban las reglas U3 L1–L2 (registro, R-99, `HORARIO_DESPACHO`) y los volúmenes de `PKI-CA` y `CA-RAIZ` estaban vacíos desde el 17-11 a las 22:09. Además, `opt4` "DMZ" estaba asignada a `vtnet0` y deshabilitada. Todo se rehízo; las reglas, desde cero con la numeración del informe.
- **PKI nueva.** Las huellas cambiaron: raíz SHA-256 `FB:B9:3F:39:…:7B:65:E1` (vence 2036-11-17), emisora `DD:03:1A:8B:…:0F:99:03` (vence 2031-11-19). Las huellas del 13-11 de [INFORME_MEJORAS.md §3.1](INFORME_MEJORAS.md) ya no corresponden.
- **Ruta del proyecto.** GNS3 tiene abierto `eval1` desde `~/GNS3/projects/e96f2260-fe77-4449-8ab7-4650c7a9cd3d/eval1.gns3`, no desde este repositorio. El `eval1.gns3` del repositorio es del 13-11 y no tiene `B2B-DMZ` ni `REMOTO-SUPERVISOR`.
