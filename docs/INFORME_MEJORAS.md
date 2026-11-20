# Informe de mejoras de seguridad del laboratorio `eval1`

Este documento registra, a medida que avanza el trabajo, cada mejora de seguridad aplicada al laboratorio a partir de [informe.pdf](informe.pdf): qué había antes, qué hay ahora, qué requisito del informe cumple y cómo se comprobó. También lista las limitaciones del entorno virtualizado (GNS3 en un solo equipo) y en qué se aparta el laboratorio de una implementación real.

- **Para quién:** el equipo del proyecto, los compañeros y el profesor.
- **El "antes":** [linea-base/INFRAESTRUCTURA.md](linea-base/INFRAESTRUCTURA.md), congelado a propósito como línea base.
- **El plan completo y la bitácora técnica (en inglés):** [../odd/tasks/informe-hardening.md](../odd/tasks/informe-hardening.md).

> Última actualización: 2026-11-20, evaluación sumativa U3 aplicada en el laboratorio: DMZ B2B, VPN IPsec con certificados, OpenVPN por perfiles, Suricata IPS, eventos en Wazuh, respaldos y capturas ([U3_SUMATIVA.md](U3_SUMATIVA.md)). Acceso GNS3 por `10.163.179.125:3080` comprobado.

---

## 1. Estado general

Acceso GNS3: `10.163.179.125:3080`, comprobado el 2026-11-20 (API y consolas).

| Fase | Objetivo | Estado |
|---|---|---|
| 0 | Respaldos y verificación de lo que soporta el laboratorio | Terminada (2026-11-13) |
| 1 | PKI propia: CA raíz fuera de línea y CA emisora con claves en HSM | T1.1 y T1.2 rehechas el 2026-11-20 (la PKI del 13-11 se perdió, ver L15). T1.3 en parte: los firewalls tienen certificado de PKI-CA para IPsec y OpenVPN; la GUI web sigue con el autofirmado |
| 2 | Endurecer lo existente: VPN con certificados, TLS 1.3 en el WMS, reglas mínimas, gestión del switch | En curso: VPN con certificados y un solo Child SA (T2.1), reglas mínimas R-01 a R-99 (T2.3) y gestión del switch solo desde VLAN 99, terminadas el 2026-11-20; falta pasar el WMS a 443 (T2.2) |
| 3 | Syslog sobre TLS hacia Wazuh y relé syslog en VLAN 99 | Pendiente (sigue UDP 514 sin cifrar) |
| 4 | Detección: IPS en el firewall e IDS de red en un puerto espejo | IPS en el firewall terminado (T4.1, 2026-11-20); IDS en puerto espejo pendiente |
| 5 | Canales nuevos (acceso remoto C4, pasarela AS2 C3a) y prueba de aceptación | En parte (2026-11-20): OpenVPN para supervisores y pasarela B2B HTTPS en la DMZ. Faltan IKEv2 con EAP, AS2 y la prueba de aceptación |

Scripts locales disponibles para iniciar/detener GNS3 y ZeroTier y controlar reglas UFW. Probados con comandos simulados; no aplicados al laboratorio. Véase [CONTROL_GNS3.md](CONTROL_GNS3.md).

## 2. Resumen antes → después

Una fila por cambio ya aplicado en el laboratorio.

| Área | Antes | Después | Requisito del informe | Tarea |
|---|---|---|---|---|
| PKI | No existía. El túnel IPsec usa una clave compartida (PSK) y las interfaces web usan certificados autofirmados | Jerarquía de dos niveles: `CA-RAIZ` (RSA-3072, 10 años, sin red) y `PKI-CA` (CA emisora RSA-3072, 5 años, en VLAN 99) | POL-04, Cuadro D | T1.1 |
| Claves de las CA | — | Generadas dentro de un token PKCS#11 (SoftHSM2) y marcadas como no extraíbles. No existe ningún archivo de clave privada | POL-04: "claves privadas no exportables" | T1.1 |
| Switch `SW-ZONA-CENTRO` | Gi0/1 y Gi0/3 en VLAN 10, libres | Gi0/1 en VLAN 99 (`PKI-CA`); Gi0/3 en VLAN 99, reservado para el relé syslog | POL-04 (CA en el segmento de gestión) | T1.1 |
| Emisión de certificados | — | `pki-emitir` con cuatro perfiles (servidor, equipo, usuario, socio). Exige el algoritmo del informe (ECDSA P-256 1 año; RSA-3072 2 años para socios) y la clave se genera en el equipo que la usa | POL-04, Cuadro D | T1.2 |
| Revocación | No existía (una PSK no se puede revocar por equipo) | `pki-revocar`; CRL de la emisora válida 7 días, regenerada cada día y en cada revocación; OCSP en 10.99.0.10:2560 | POL-04: "CRL de la emisora cada 7 días [...] OCSP" | T1.2 |
| Firewall `FW-ZONA-CENTRO` | Sin acceso de las VLAN 10/20/30 a la VLAN 99 | Regla 95: VLAN 10/20/30 → 10.99.0.10 solo TCP 80 (CRL) y 2560 (OCSP) | POL-04 (validación de revocación); excepción de texto claro prevista (objetos firmados) | T1.2 |
| Registro del firewall | Ninguna regla registraba; solo quedaba en el log lo que caía en el bloqueo por defecto, sin regla propia | Todas las reglas registran. Cada interfaz termina en una regla explícita de bloqueo con registro (R-99), y cada descripción empieza con su código R-xx | POL-06 (monitoreo); condición 3 del caso U3 | U3 L1 |
| Alcance del vehículo (C2) | El vehículo llegaba a toda la VLAN 10 y la VLAN 10 al vehículo (reglas 10 y 200) | Esas dos reglas quedan deshabilitadas: el vehículo solo llega al WMS | POL-05 (mínimo privilegio), canal C2 | U3 L1 (parte de T2.3) |
| Salida a Internet de la VLAN 10 | DNS, NTP, HTTP 80 y HTTPS | DNS, NTP y HTTPS; HTTP 80 sin cifrar queda bloqueado | POL-01 (canales cifrados) | U3 L1 (parte de T2.3) |
| Horario del vehículo | El vehículo llegaba al WMS a cualquier hora | Solo de lunes a sábado, de 07:00 a 20:00 (horario `HORARIO_DESPACHO`); fuera de horario se cortan sus conexiones | POL-05; regla avanzada de la guía U3 | U3 L2 |
| Acceso al servidor GNS3 | `10.82.151.125` | `10.163.179.125`; referencias de API, Telnet y VNC actualizadas | Operación del laboratorio compartido; no añade requisitos de seguridad | Actualización documental (2026-11-18) |
| Control local | Comandos manuales | `scripts/labctl.py` reúne arranque, apagado y reglas UFW propias; implementación sin ejecución real | Operación del laboratorio; no añade requisitos del informe | Scripts (2026-11-18) |
| DMZ B2B (F-05) | Solo una propuesta; `opt4` "DMZ" a medio crear (en `vtnet0`, deshabilitada) | VLAN 40 192.168.40.0/24 en el switch y el firewall; nodo `B2B-DMZ` (nginx HTTPS, certificado RSA-3072 de PKI-CA); NAT 203.0.113.2:443 → 192.168.40.10 (R-05A); la DMZ solo llega al WMS 8069 (R-05B) | Informe U3: condición 1 del caso, F-05; informe U2 canal C3a | U3 sumativa (2026-11-20) |
| VPN del vehículo (F-02) | Clave compartida (PSK) y dos Child SA (WMS y VLAN 10) | Certificados X.509 de PKI-CA en los dos extremos; un solo Child SA 192.168.20.5/32 ↔ 172.16.50.0/24; AES-256-GCM con ECP-521 | POL-04, POL-05; T2.1 | U3 sumativa (2026-11-20) |
| Acceso remoto (F-03) | No existía | OpenVPN UDP 1194, pool 10.8.0.0/24, certificado de usuario de PKI-CA, solo ruta al WMS; R-03A permite 8069, R-03B bloquea VLAN 99 y la BD | Informe U3 F-03, perfil P-03; T5.2 (en parte) | U3 sumativa (2026-11-20) |
| IPS | Suricata instalado, apagado | Suricata 8.0.6 en modo IPS (Netmap) en `vtnet1` (WAN) y `vtnet0` (troncal), ET Open scan/exploit/web_server en *drop*, alertas a Wazuh | Informe U3 (IPS), T4.1 | U3 sumativa (2026-11-20) |
| Reglas del firewall | Reglas U3 L1–L2 perdidas (ver L15) | Tabla R-01 a R-99 del informe sumativo, con registro en todas, R-99 en 8 interfaces, alias del informe y NAT de salida explícito para la VLAN 10 | Informe U3 §4.2; POL-05, POL-06 | U3 sumativa (2026-11-20) |
| Wazuh | Solo alertaba bloqueos repetidos (87702) | Decodificador del EVE de Suricata por syslog y reglas locales 100200–100212: un evento visible por cada E-01 a E-04 | Informe U3 §5.2; resuelve L13 | U3 sumativa (2026-11-20) |
| Gestión del switch | SVI en VLAN 10/20/30: el switch respondía SSH desde la VLAN 10 | Solo Vlan99; ACL `MGMT_VTY` en las VTY (10.99.0.0/25) | POL-05, F-04 (administración solo desde VLAN 99) | U3 sumativa (2026-11-20) |
| Respaldos | Copias internas del 13-11 | XML de los dos firewalls y running-config del switch del 2026-11-20, fuera de Git | Informe U3 §5.3 | U3 sumativa (2026-11-20) |

## 3. Detalle de cada mejora

### 3.0 Fase 0: preparación (sin cambios de comportamiento)

Antes de tocar nada se respaldó el laboratorio y se verificó qué soporta cada componente. Así ninguna mejora depende de una suposición.

| Qué se hizo | Resultado |
|---|---|
| Respaldo completo | `eval1.gns3.pre-hardening` y [../configs/backup-pre-hardening/](../configs/backup-pre-hardening/): topología, `config.xml` de los dos OPNsense y running-config del switch y de `ISP-WAN` |
| Prueba de que el laboratorio funcionaba | Túnel IPsec establecido; el vehículo llega a Odoo; el dashboard de Wazuh abre desde `WEBTERM-ADMIN` |
| Verificación de algoritmos en OPNsense | strongSwan soporta ChaCha20-Poly1305, X25519, autenticación por certificado y EAP |
| Verificación de syslog sobre TLS | OPNsense puede enviar por TLS; Wazuh no lo recibe de forma nativa (se usará rsyslog delante) |
| Verificación del puerto espejo (SPAN) | Funciona en el switch IOSv-L2 |
| Verificación de OpenAS2 y Suricata | OpenAS2 no soporta AES-GCM; Suricata está disponible en OPNsense |

Los resultados que obligan a apartarse del informe están en la sección 4.

### 3.1 T1.1: jerarquía de CA con claves en HSM (2026-11-13)

**Qué pide el informe (POL-04, Cuadro D):** una jerarquía de dos niveles: CA raíz fuera de línea (RSA-3072, 10 años, clave en token HSM) y CA emisora (RSA-3072, 5 años, clave en HSM), ubicada en la VLAN de gestión, con claves privadas no exportables.

**Qué se hizo:**

| Elemento | Detalle |
|---|---|
| Imagen Docker `pki-ca-gns3` | Alpine 3.24 con OpenSSL 3.5, SoftHSM2 2.7 y el proveedor PKCS#11 de libp11. Fuente: [../docker/pki-ca-gns3/](../docker/pki-ca-gns3/) |
| Nodo `CA-RAIZ` | Contenedor **sin interfaces de red** (solo `lo`). Firma únicamente el certificado de la CA emisora y su propia CRL |
| Nodo `PKI-CA` | CA emisora en `SW-ZONA-CENTRO` Gi0/1, VLAN 99, IP 10.99.0.10/25, puerta de enlace 10.99.0.1 |
| Certificado raíz | `CN=Empresa CA Raiz`, RSA-3072, SHA-256, válido hasta 2036-11-10 |
| Certificado emisora | `CN=Empresa CA Emisora`, RSA-3072, válido hasta 2031-11-12, `pathlen:0` (solo emite certificados finales), con punto de distribución de CRL `http://10.99.0.10/crl/raiz.crl` |
| CRL de la raíz | Válida 365 días; se publicará en `PKI-CA` en T1.2 |
| Persistencia | `/srv/ca` (base de datos de la CA) y `/var/lib/softhsm/tokens` (tokens) son volúmenes de GNS3: sobreviven a reinicios |

**Cómo se firmó la CA emisora sin dar red a la raíz:**

1. `PKI-CA` genera su clave dentro de su token y produce una solicitud (CSR).
2. La CSR pasa a `CA-RAIZ` a través del equipo anfitrión, nunca por la red del laboratorio.
3. `CA-RAIZ` la firma con la clave de su token.
4. El certificado vuelve a `PKI-CA` por el mismo camino. Antes de instalarlo, `PKI-CA` comprueba que encadena con la raíz y que corresponde a la clave de su token.

**Cómo se comprobó (después de parar y arrancar ambos nodos):**

- [x] `openssl verify -CAfile raiz.pem emisora.pem` → `OK`, también comprobando la CRL de la raíz.
- [x] Las dos claves figuran en el token como `sensitive, always sensitive, never extractable`.
- [x] No hay ningún archivo con `PRIVATE KEY` en `/srv/ca` de ninguno de los dos nodos.
- [x] `CA-RAIZ` no tiene interfaces de red.
- [x] La instalación rechaza un certificado que no corresponde a la clave del token.
- [x] `PKI-CA` llega a la puerta de enlace 10.99.0.1 (ARP resuelto y HTTPS del firewall accesible).

**Observación:** el plan pedía comprobar con `ping 10.99.0.1`, pero el firewall no responde ICMP a su propia IP desde la VLAN 99 para ningún equipo (tampoco `WEBTERM-ADMIN`). Es la política vigente: desde VLAN 99 solo se permite ping hacia las VLAN 10, 20 y 30. Se comprobó la conectividad por HTTPS y no se cambió el firewall.

Para repetir las comprobaciones, desde el anfitrión:

```bash
docker exec GNS3.PKI-CA.54ea9572-79a3-48cc-9bb4-8c652368f315 sh -c 'cd /srv/ca/emisora && openssl verify -CAfile raiz.pem emisora.pem'
```

### 3.2 T1.2: emisión, revocación, CRL y OCSP (2026-11-13)

**Qué pide el informe (POL-04, Cuadro D):** certificados finales de 1 año para los internos y 2 años para los socios, revocación con CRL de la emisora cada 7 días y OCSP para el concentrador VPN y la pasarela AS2.

**Qué se hizo** (detalle completo en [PKI.md](PKI.md)):

| Elemento | Detalle |
|---|---|
| `pki-emitir <perfil> <nombre> --csr <archivo>` | Firma la CSR que generó el propio equipo. El sujeto y el SAN los fija la CA, no la CSR. Si el equipo no puede generar CSR, la clave se crea en memoria, se entrega como PKCS#12 y no queda en la CA |
| Perfiles | `servidor`, `equipo`, `usuario` (ECDSA P-256, 1 año) y `socio` (RSA-3072, 2 años). Rechaza una CSR con otro tipo de clave |
| `pki-revocar <nombre> [motivo]` | Revoca, publica la CRL nueva y OCSP responde `revoked` desde la consulta siguiente |
| CRL | Válida 7 días. Se regenera al arrancar, cada día (cron) y en cada revocación, así que nunca vence mientras `PKI-CA` esté encendida |
| OCSP | Respondedor en 10.99.0.10:2560 con su propio certificado (`OCSPSigning`), cuya clave también está en el token |
| Publicación | `http://10.99.0.10/ca/`, `/crl/` y `/certs/` (lighttpd). Los certificados finales llevan estas URL dentro |
| Regla 95 | VLAN 10, 20 y 30 → 10.99.0.10 solo TCP 80 y 2560. Script: [../configs/opnsense/pki-regla.php](../configs/opnsense/pki-regla.php); respaldo previo: `/conf/backup/config-pre-t1.2.xml` |

**Cómo se comprobó:**

- [x] La CRL se descarga desde la VLAN 99 (`WEBTERM-ADMIN`), la VLAN 10 (`alpine-1`), la VLAN 20 (`odoo-1`) y la VLAN 30 (`WAZUH-MANAGER`).
- [x] Desde la VLAN 10, otro puerto de `PKI-CA` (443) sigue bloqueado.
- [x] Certificado de prueba, con la clave generada en `WEBTERM-ADMIN`: OCSP responde `good` desde la VLAN 99 y desde la VLAN 20.
- [x] Tras revocarlo, OCSP responde `revoked` desde las dos VLAN, y su número de serie aparece en la CRL nueva.
- [x] OCSP sigue respondiendo con conexiones vacías abiertas contra el puerto 2560.
- [x] El emisor rechaza un nombre duplicado y una clave RSA-2048 en un perfil que exige P-256 (probado en contenedores desechables).
- [x] `WEBTERM-ADMIN` sigue entrando a `https://10.99.0.1` y al dashboard de Wazuh después del cambio de reglas.

**Decisión:** los certificados finales de la lista del plan (firewalls, WMS, SIEM, relé, usuarios, AS2) no se emitieron todavía. Cada uno se emite en la tarea que configura su equipo, para que la clave se genere en ese equipo. Emitirlos ahora habría obligado a crear las claves en la CA. La lista y su tarea están en [PKI.md §4](PKI.md).

**Problema encontrado y corregido:** el respondedor `openssl ocsp` atiende de a una conexión. Una conexión vacía (un simple escaneo de puertos) lo dejó colgado y consumiendo CPU. Ahora lighttpd recibe las conexiones en 2560 y solo le pasa peticiones completas.

### 3.3 U3 L1–L2: reglas con registro, mínimo privilegio y horario (2026-11-13)

**Qué pide:** la guía de la Unidad 3 exige reglas básicas y avanzadas (objetos, horarios, denegación por defecto y registro por regla) y eventos permitidos y bloqueados ligados a una regla. El informe de la U2 limita el canal C2 al WMS (POL-05) y pide canales cifrados (POL-01).

**Qué se hizo** (detalle y tabla R-xx en [U3_SEGURIDAD_PERIMETRAL.md](U3_SEGURIDAD_PERIMETRAL.md)):

| Elemento | Detalle |
|---|---|
| Respaldo | `/conf/backup/config-pre-u3.xml` en `FW-ZONA-CENTRO` y en `FW-VEHICULO-01`. El vehículo no cambió |
| Registro | Las 16 reglas de paso activas de la sede registran en el log (también las 2 deshabilitadas, por si se reactivan) |
| R-99 | Regla 9000 *block + log* en VLAN 10, 20, 30, 99, IPsec y WAN |
| Mínimo privilegio | Reglas 10 (VLAN 10 → vehículo) y 200 (vehículo → VLAN 10) deshabilitadas; regla 30 sin HTTP 80 |
| Horario | `HORARIO_DESPACHO` (lunes a sábado, 10:00–23:00 UTC = 07:00–20:00 Chile) en la regla 210. OPNsense agregó solo la tarea cron que reevalúa el horario cada 15 minutos |
| Objetos | Alias `WMS_ODOO`, `PUERTOS_WMS` y `PUERTOS_SALIDA_V10` |
| Script | [../configs/opnsense/u3-reglas.php](../configs/opnsense/u3-reglas.php) |

**Cómo se comprobó** (13-11, 21:09–21:15 Chile):

- [x] `alpine-1` (VLAN 10) → WMS 8069: `303`; → `https://example.com`: `200`; → `http://example.com`: bloqueado (antes del cambio, `200`); → `postgres-1:5432`: bloqueado.
- [x] `TABLET-CONDUCTOR`, fuera de horario → WMS: sin respuesta, con el bloqueo registrado por R-99 de IPsec; → `alpine-1`: 100 % de pérdida.
- [x] Con el horario abierto por un momento a todo el día: tablet → WMS `303` y → `postgres-1` bloqueado. Después se restauró el horario y la regla volvió a quedar inactiva.
- [x] El túnel siguió `ESTABLISHED` con sus 2 Child SA durante todo el cambio.
- [x] La VLAN 99 sigue administrando: `PKI-CA` → `https://10.99.0.1` `200`, → Wazuh 5601 `302`, → SSH del switch abierto.
- [x] El log del firewall muestra líneas `pass` y `block` con la etiqueta de cada regla (113 líneas `pass` en los primeros minutos).

**Observaciones:** el Child SA 192.168.10.0/24 ↔ 172.16.50.0/24 sigue negociado, aunque el firewall ya no deja pasar ese tráfico. Se quita al rehacer la VPN con certificados (T2.1). Wazuh no alerta sobre accesos permitidos (ver L13).

---

### 3.4 Actualización de la IP del servidor (2026-11-18)

**Qué:** se actualizó la IP de acceso en las guías de colaboración, consolas y OPNsense, a petición del propietario.

**Verificación:** revisión de referencias documentales; no se probó la conexión ni se cambió la configuración del servidor.

**Observaciones:** los puertos se mantienen. La dirección anterior se conserva en los registros históricos.

### 3.5 Scripts de control local (2026-11-18)

**Qué:** `scripts/labctl.py` permite gestionar ZeroTier, una unidad GNS3 de usuario y reglas UFW limitadas a la IP del servidor y su interfaz ZeroTier. No modifica reglas ajenas.

**Verificación:** 15 pruebas simuladas aprobadas y revisión independiente de tres fallos corregidos; pendiente la validación operativa. Revisión nativa no disponible en esta sesión (`immutable_review_transport_unsupported`). Uso y alcance en [CONTROL_GNS3.md](CONTROL_GNS3.md).

**Observaciones:** se excluyen los puertos 16261 y 16262, de Project Zomboid. No se ha cambiado el laboratorio durante esta implementación.

### 3.6 Evaluación sumativa U3: DMZ, VPN con certificados, OpenVPN, IPS y eventos en Wazuh (2026-11-20)

**Qué pide:** el informe sumativo (versión con la retroalimentación del profesor) describe una DMZ B2B con NAT 443, la VPN del vehículo con certificados y un solo Child SA, OpenVPN por perfiles con la VLAN 99 bloqueada, Suricata en modo IPS sobre las interfaces padre, eventos E-01 a E-04 en Wazuh, respaldos XML y diez figuras con capturas.

**Qué se hizo:** detalle completo, scripts y capturas en [U3_SUMATIVA.md](U3_SUMATIVA.md); estado de la red en [INFRAESTRUCTURA_ACTUAL.md](INFRAESTRUCTURA_ACTUAL.md).

| Elemento | Detalle |
|---|---|
| Topología | Nodos `B2B-DMZ` (Gi1/3, VLAN 40) y `REMOTO-SUPERVISOR` (`ISP-WAN` Gi0/3, 192.0.2.10); `FW-ZONA-CENTRO` con 4 GB |
| PKI | Raíz y emisora regeneradas; certificados `fw-centro`, `vehiculo-01`, `as2` y `supervisor1` firmados desde CSR generadas en cada equipo |
| Firewall | [u3-sumativa-centro.php](../configs/opnsense/u3-sumativa-centro.php), [u3-ipsec-pki.php](../configs/opnsense/u3-ipsec-pki.php), [u3-openvpn.php](../configs/opnsense/u3-openvpn.php), [u3-suricata.php](../configs/opnsense/u3-suricata.php) |
| Wazuh | [u3_decoder.xml y u3_rules.xml](../docker/wazuh-gns3/manager/local/), también copiados al Dockerfile del manager |
| Capturas | 34 imágenes en [img/u3-sumativa/](img/u3-sumativa/), mapeadas a las Figuras 1–10 del informe |

**Cómo se comprobó:**

- [x] P-01 a P-08 del informe: los ocho con el resultado esperado (tabla en [U3_SUMATIVA.md §2](U3_SUMATIVA.md)).
- [x] Túnel IPsec `ESTABLISHED` con *public key authentication* en los dos extremos y un solo Child SA `INSTALLED`.
- [x] Cliente OpenVPN: verifica la cadena `Empresa CA Raiz` → `Empresa CA Emisora` → `fw-centro.empresa.local`, recibe 10.8.0.2 y solo la ruta 192.168.20.5.
- [x] Suricata bloqueó en línea el escaneo nmap desde 192.0.2.10 (`action: blocked`, 7 firmas ET SCAN distintas) y la red interna siguió funcionando con Netmap en el troncal.
- [x] Wazuh mostró E-01 (100210), E-02 (100212 y 87702), E-03 (100201) y E-04 (100211) con hora, origen, destino y regla.
- [x] Respaldo XML descargado desde la GUI (120 KB, 22:18 UTC) y comprobado como XML válido.
- [x] Después de quitar las SVI del switch: desde la VLAN 10, SSH a 192.168.10.2 y a 10.99.0.2 sin respuesta; desde la VLAN 99, SSH correcto.

**Observaciones:** el laboratorio vivo no tenía los cambios del 13-11 (L15), así que se rehicieron reglas y PKI desde cero. Hay siete diferencias entre el texto del Word y lo implementado (ECP-521, cliente en contenedor y no Kali, OpenVPN solo con certificado, respaldos sin cifrar, puertos extra de R-04, reglas extra de la WAN, horas reales de los eventos); están en [U3_SUMATIVA.md §7](U3_SUMATIVA.md) para corregir el informe.

## 4. Limitaciones del entorno virtualizado y desviaciones del informe

El laboratorio corre completo en un solo equipo con GNS3, contenedores Docker y máquinas virtuales. Algunas exigencias del informe no se pueden reproducir tal cual. Cada fila dice qué pide el informe, qué hace el laboratorio y por qué.

**Estado:** *Confirmada* = observada en el laboratorio. *Prevista* = conocida desde la fase 0; se confirmará en la tarea indicada.

### 4.1 Limitaciones del entorno

| # | Informe pide | El laboratorio hace | Por qué / impacto | Estado |
|---|---|---|---|---|
| L1 | Claves de las CA en HSM (token y HSM de red) | SoftHSM2: HSM por software dentro del contenedor | No hay hardware HSM. Las claves no salen del token por la interfaz PKCS#11, pero el token es un archivo en el disco del anfitrión y su PIN está en un archivo con permisos 0600. Un HSM real protege la clave también ante quien controla el anfitrión | Confirmada (T1.1) |
| L2 | CA raíz fuera de línea | Contenedor sin interfaces de red en el mismo anfitrión | Está aislada de la red del laboratorio, pero comparte el equipo físico. Los archivos se trasladan por el anfitrión en vez de por un medio extraíble en una ceremonia de firma | Confirmada (T1.1) |
| L3 | Switch multicapa (L3) | Switch L2 (IOSv-L2) y enrutamiento entre VLAN en el firewall | El enrutamiento entre VLAN lo hace el firewall. El resultado de seguridad es igual o mejor, porque todo el tráfico entre VLAN pasa por el firewall | Confirmada (supuesto A2) |
| L4 | Teléfono o tableta del conductor con VPN propia | Router OPNsense del vehículo (`FW-VEHICULO-01`) con un túnel sitio a sitio | GNS3 no emula bien un teléfono con cliente IPsec. El certificado identifica al router del vehículo | Confirmada (supuesto A1) |
| L5 | Internet, socios y usuarios remotos reales | Segmento simulado 192.0.2.0/24 (direcciones de documentación, RFC 5737) en `ISP-WAN` Gi0/3, con un solo contenedor (`REMOTO-SUPERVISOR`) que hace de supervisor, socio y atacante | No se exponen servicios a Internet real | Confirmada (U3 sumativa) |
| L6 | Recursos de un equipo productivo | El firewall central pasó de 2 a 4 GB de RAM para Suricata | El anfitrión comparte memoria entre unos 20 nodos. Con 18 nodos encendidos el anfitrión usó 19 de 31 GB (2026-11-20) | Confirmada (U3 sumativa) |
| L7 | Credenciales robustas | Contraseñas del laboratorio en texto plano (`test123`) en la documentación | Es un laboratorio de estudio; se documentan a propósito para poder evaluarlo. No se reutilizan en entornos reales | Confirmada |
| L8 | Respondedor OCSP de producción, en el concentrador VPN y la pasarela AS2 | Un único respondedor central (`openssl ocsp`, de un proceso) detrás de lighttpd, en `PKI-CA` | `openssl ocsp` está pensado para pruebas. El proxy lo protege de conexiones colgadas, pero atiende las consultas de a una. Su puerto interno 2561 queda accesible desde la VLAN 99, el segmento de confianza. Para el volumen del laboratorio alcanza | Confirmada (T1.2) |
| L9 | CRL publicada sin cortes | CRL válida 7 días, regenerada cada día por `PKI-CA` | Si el laboratorio queda apagado más de 7 días, la CRL vence y los equipos que la exigen rechazan certificados hasta que `PKI-CA` arranque y la regenere | Confirmada (T1.2) |
| L12 | Horario de despacho en hora local | El horario se escribe en UTC (10:00–23:00), porque el firewall usa UTC | Las horas en UTC deben ajustarse a mano cuando Chile cambia entre UTC−3 y UTC−4. Los registros del firewall quedan en UTC | Confirmada (U3 L2) |
| L13 | El SIEM recibe los eventos relevantes del perímetro | Wazuh recibe todo el `filterlog`, pero sus reglas incluidas solo alertan sobre bloqueos repetidos desde un mismo origen (regla 87702) | Un acceso permitido o un bloqueo aislado quedan en el log del firewall, pero no aparecen como alerta en el dashboard. **Resuelta en parte el 2026-11-20:** reglas locales 100210–100212 alertan los bloqueos de E-01, E-02 y E-04; los accesos permitidos siguen sin alerta | Confirmada (U3 L1); resuelta en parte (U3 sumativa) |
| L15 | Cambios persistentes en el laboratorio compartido | El 2026-11-20 el laboratorio vivo no tenía las reglas U3 L1–L2 ni la PKI del 13-11: los volúmenes de `PKI-CA` y `CA-RAIZ` estaban vacíos desde el 17-11 22:09 y `opt4` "DMZ" estaba a medio crear | Probablemente el proyecto se reabrió o se reconstruyó el 17-11 desde otra copia. Se rehízo todo y la PKI tiene huellas nuevas. Los scripts de `configs/opnsense/` permiten repetir la configuración | Confirmada (U3 sumativa) |
| L16 | Un solo archivo de topología | GNS3 tiene abierto `eval1` desde `~/GNS3/projects/e96f2260-…/eval1.gns3`; el `eval1.gns3` del repositorio es del 13-11 | El repositorio no refleja los nodos `B2B-DMZ` y `REMOTO-SUPERVISOR` hasta que se copie ese archivo o se abra el proyecto desde el repositorio | Confirmada (U3 sumativa) |

### 4.2 El servidor GNS3 compartido

La IP vigente informada por el propietario el 2026-11-18 es `10.163.179.125`; su conectividad y la configuración de acceso no se han vuelto a comprobar. La confirmación de L10 corresponde a la revisión del 2026-11-13.

Desde el 2026-11-13 el servidor GNS3 se comparte con los compañeros por una red ZeroTier, para que se conecten desde sus clientes. Esto no forma parte de la topología, pero cambia quién puede tocar el laboratorio.

| # | Situación | Consecuencia | Estado |
|---|---|---|---|
| L10 | El servidor escucha en `10.163.179.125:3080` (ZeroTier) con `auth = False`, y las consolas de los nodos escuchan en esa IP con `allow_console_from_anywhere = True` | Cualquier equipo de esa red ZeroTier puede usar la API de GNS3 sin contraseña (parar, borrar o modificar nodos) y abrir las consolas. Las consolas de los contenedores dan una shell de root sin contraseña: en `PKI-CA` eso permite firmar certificados con la CA emisora. Las consolas de los firewalls y del switch sí piden contraseña (`test123`, documentada). La protección de la PKI y la segmentación del laboratorio valen solo frente a la red simulada, no frente a quien accede al servidor GNS3 | Confirmada (2026-11-13) |
| L14 | El controlador no gestiona IPv6, otras interfaces ni conexiones establecidas; reserva UDP 16261–16262 para Project Zomboid | El cierre solo bloquea nuevas conexiones IPv4 GNS3 por ZeroTier; si GNS3 asigna los puertos reservados a enlaces remotos, hay que reasignarlos | Confirmada en diseño; ejecución real pendiente |
| L11 | `auto_start = False` | Al abrir el proyecto los nodos quedan apagados y hay que arrancarlos en orden (ver [HOJA_TRAMPA.md](HOJA_TRAMPA.md)). Tras el reinicio del 2026-11-13 se arrancaron y se comprobó que todo seguía igual: switch, regla 95, túnel IPsec, PKI, Odoo y Wazuh | Confirmada (2026-11-13) |

### 4.3 Desviaciones por limitaciones del software

| # | Informe pide | El laboratorio hace | Por qué | Estado |
|---|---|---|---|---|
| D1 | Mensajes AS2 con AES-256-GCM (`AuthEnvelopedData`) | AES-256-CBC con firma previa (RSA-3072/SHA-256) y MDN firmado | OpenAS2 solo implementa cifrados CBC. El informe admite CBC como excepción registrada (§3.3) | Prevista (T5.3) |
| D2 | Cierre de sesión VPN tras 15 minutos de inactividad | Por confirmar: configuración directa de strongSwan o, si no, DPD y reautenticación cada 900 s | El modelo de OPNsense no tiene un campo de inactividad | Prevista (T5.2) |
| D3 | Syslog sobre TLS recibido por el SIEM | rsyslog con TLS 1.3 delante de Wazuh, que lo entrega localmente | Wazuh 4.14 solo recibe syslog por UDP/TCP sin cifrar | Prevista (T3.1) |
| D4 | IPS en línea sobre el tráfico VPN ya descifrado | IPS Netmap en `vtnet1` (WAN) y `vtnet0` (troncal de las VLAN). El tráfico del vehículo se inspecciona cifrado en la WAN y descifrado cuando sale por el troncal hacia la VLAN 20 | `enc0` no admite Netmap; el manual de OPNsense pide la interfaz padre para VLAN | Confirmada (U3 sumativa) |
| D5 | MFA para el acceso remoto | Hoy: OpenVPN solo con certificado de usuario. Previsto: certificado más credencial por usuario | No hay servidor TOTP; usuario y clave en OpenVPN quedan pendientes | Confirmada en parte (U3 sumativa) |
| D6 | Grupo DH ECP-256 (texto del informe U3) | ECP-521 en IKE y ESP (`aes256gcm16-sha256-ecp521`, `aes256gcm16-ecp521`) | El modelo IPsec de OPNsense 26.7 no ofrece ECP-256 con AES-GCM | Confirmada (U3 sumativa) |
| D7 | Revocación inmediata del acceso remoto por CRL/OCSP | La opción OCSP del servidor OpenVPN está marcada, pero registra `no OCSP configured`: revocar un certificado de cliente aún no corta la VPN | Falta cargar la URL OCSP de la emisora en *System → Trust* | Confirmada (U3 sumativa) |

### 4.4 Fuera del alcance de la topología

Estos controles del informe pertenecen a la aplicación, a la nube o a los dispositivos finales, y no cambian la red del laboratorio: MDM y atestación de Keystore, Argon2id, HMAC por registro en el WMS, almacenamiento WORM y sellado de logs, S/MIME (C3b), EDR, TPM y tarjetas inteligentes, y la hoja de ruta poscuántica.

---

## 5. Próximo paso

Para la entrega sumativa: corregir en el Word las siete diferencias de [U3_SUMATIVA.md §7](U3_SUMATIVA.md), pegar las capturas en las Figuras 1–10 (la Figura 1 necesita las etiquetas de zonas y puntos de control) y adjuntar `config-FW-ZONA-CENTRO.empresa.local-20261120.xml`. Copiar al repositorio el `eval1.gns3` que tiene abierto GNS3 (L16).

Validar los scripts de [CONTROL_GNS3.md](CONTROL_GNS3.md) en una sesión real (`labctl.py up`/`down`); el acceso a `10.163.179.125:3080` y a las consolas ya se comprobó el 2026-11-20.

Después de la entrega: cargar la URL OCSP en la CA de OPNsense (D7), usuario y clave para OpenVPN (D5), WMS en 443 (T2.2) y syslog sobre TLS (fase 3).
