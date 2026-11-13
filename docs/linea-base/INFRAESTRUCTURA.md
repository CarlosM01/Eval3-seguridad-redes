# Infraestructura de red — Proyecto GNS3 `eval1`

> [!IMPORTANT]
> **LÍNEA BASE CONGELADA — NO EDITAR.**
> Este documento describe la infraestructura **antes** de las mejoras de seguridad del [informe](../informe.pdf) y se conserva tal cual, como referencia del "antes", para reportar esas mejoras.
>
> - **No refleja la topología actual.** Por ejemplo, aún lista `CiscoRTT-1`, la VM `SIEM` y `Mint-1`, que ya no están en `eval1.gns3`.
> - **Estado actual:** `eval1.gns3` (nodos y enlaces) y `docs/INFRAESTRUCTURA_ACTUAL.md` (se crea al implementar las mejoras).
> - **Plan de mejoras:** [odd/tasks/informe-hardening.md](../../odd/tasks/informe-hardening.md).
>
> Congelado el 2026-11-13 por decisión del dueño del proyecto. Solo se agregó este aviso y se ajustaron las rutas de los enlaces al moverlo a `docs/linea-base/`.

Documentación de la topología, el direccionamiento y la configuración del router y del switch del laboratorio `eval1`.

**Fuentes verificadas (2026-10-24):**
- `eval1.gns3`: nodos, enlaces y diagrama.
- Consola del router `RTR-ZONA-CENTRO`: `show running-config`, `show ip interface brief`, `show ip route`, `show ip dhcp binding` y `show ip nat translations`. La configuración en ejecución es igual a la guardada en disco.
- Consola del switch `SW-ZONA-CENTRO`: `show running-config`, `show startup-config`, `show vlan brief`, `show interfaces trunk`, `show interfaces status`, `show ip interface brief` y `show ip ssh`. La configuración en ejecución es igual a la guardada.

Las salidas completas están en [configs/](../../configs/).

> **Actualización 2026-11-11.** El borde de la sede ahora es el firewall OPNsense `FW-ZONA-CENTRO`, conectado al operador simulado `ISP-WAN`. Hay una red nueva, la del vehículo de un conductor en ruta, unida a la VLAN 10 por IPsec. `RTR-ZONA-CENTRO` sigue en el proyecto, apagado y sin enlaces. Su configuración (§6) queda como referencia de lo que reemplazó el firewall. Detalle completo en [VPN_VEHICULO.md](../VPN_VEHICULO.md).
>
> **Actualización 2026-11-11 (Wazuh).** El SIEM ahora son tres contenedores Docker en la VLAN 30: `WAZUH-MANAGER` (192.168.30.5, la IP de antes), `WAZUH-INDEXER` (.6) y `WAZUH-DASHBOARD` (.7). La VM `SIEM` sigue en el proyecto, apagada y sin enlaces. Detalle en [WAZUH_DOCKER.md](../WAZUH_DOCKER.md).

---

## 1. Resumen

| Elemento | Valor |
|---|---|
| Nodos | 19 (9 QEMU, 8 Docker, 1 NAT, 1 switch Ethernet de GNS3) |
| Enlaces | 15 |
| Borde de la sede | `FW-ZONA-CENTRO`, OPNsense 26.7 (antes `RTR-ZONA-CENTRO`, IOSv 15.9, hoy desconectado) |
| Operador | `ISP-WAN`, IOSv 15.9: Internet por NAT1, red móvil 100.64.20.0/24 y CGNAT |
| Switch | `SW-ZONA-CENTRO` (nodo GNS3 `CiscoSW-1`), IOSv-L2 |
| Modelo de enrutamiento entre VLANs | Router-on-a-stick: un trunk 802.1Q (firewall `vtnet0` ↔ switch Gi0/0) |
| VLANs | 10, 20, 30, 99 |
| Dominio | `empresa.local` |
| Salida a Internet | Solo la VLAN 10, por reglas del firewall; el ISP hace NAT hacia NAT1 |
| VPN | IPsec IKEv2 entre `FW-ZONA-CENTRO` y `FW-VEHICULO-01`: VLAN 10 ↔ 172.16.50.0/24 |

---

## 2. Topología

```
                         Internet
                            │
                     NAT1 (nat0 / virbr0)
                            │ Gi0/0
                     ┌──────┴──────┐  Gi0/2 100.64.20.1/24   ┌────────────────┐
                     │   ISP-WAN   ├─────────────────────────┤ FW-VEHICULO-01 ├── TABLET-CONDUCTOR
                     └──────┬──────┘   (red móvil, CGNAT)     └───────┬────────┘   172.16.50.10
                     Gi0/1  │ 203.0.113.1/30                          ║
                    vtnet1  │ 203.0.113.2/30                          ║ túnel IPsec
                  ┌─────────┴─────────┐                               ║
                  │  FW-ZONA-CENTRO   │═══════════════════════════════╝
                  └─────────┬─────────┘
                   vtnet0   │  Trunk 802.1Q (vlan010 vlan020 vlan030 vlan099)
                     Gi0/0  │  (VLANs permitidas: 10,20,30,99)
                  ┌─────────┴─────────┐
                  │  SW-ZONA-CENTRO   │
                  └─┬──────┬──────┬───┘
       ┌────────────┘      │      └─────────────────────┐
       │                   │                            │
  VLAN 10            VLAN 20          VLAN 30                 VLAN 99
  Gi0/2 alpine-1     Gi1/1 postgres-1 Gi2/1 WAZUH-MANAGER     Gi3/1 SoporteTI
                     Gi1/2 odoo-1     Gi2/2 WAZUH-INDEXER     Gi3/2 Mint-1
                                      Gi2/3 WAZUH-DASHBOARD   Gi3/3 WEBTERM-ADMIN

  Sin enlaces: Kali-1, RTR-ZONA-CENTRO (reemplazado por FW-ZONA-CENTRO) y SIEM (VM reemplazada por Wazuh en Docker)
```

---

## 3. Plan de direccionamiento

| VLAN | Nombre en el switch | Descripción en el router | Red | Gateway | DHCP | Puertos de acceso |
|---|---|---|---|---|---|---|
| 10 | `ADMIN_LOGISTICA` | Administrativos y Logística | 192.168.10.0/24 | 192.168.10.1 | `POOL_VLAN10_USUARIOS` (excluye .1–.10) | Gi0/1–Gi0/3, Gi1/0 |
| 20 | `INVENTARIO_BODEGAS` | Inventario y Gestión Bodegas | 192.168.20.0/24 | 192.168.20.1 | `POOL_VLAN20_INVENTARIO` (excluye .1–.10) | Gi1/1–Gi1/3, Gi2/0 |
| 30 | `SERVIDORES_SIEM` | Servidor SIEM y Monitoreo | 192.168.30.0/24 | 192.168.30.1 | No | Gi2/1–Gi2/3, Gi3/0 |
| 99 | `MGMT_TI` | Administración TI | 10.99.0.0/25 | 10.99.0.1 | No | Gi3/1–Gi3/3 |

- DNS entregado por DHCP: `1.1.1.1`, `8.8.8.8`.
- IP fija conocida: SIEM Wazuh `192.168.30.5` (aparece en las ACL y es el destino de `logging host`). Hoy es `WAZUH-MANAGER`; el indexer es .6 y el dashboard .7.
- La VLAN 1 existe en el switch, pero no tiene puertos de acceso. Es la VLAN nativa del trunk.
- La dirección `.2` de cada VLAN (10.99.0.2 en la 99) es una SVI del switch (§7.2).

---

## 4. Inventario de nodos

| Nodo GNS3 | Tipo | Imagen / SO | CPU | RAM | Consola | Puerto del switch | VLAN |
|---|---|---|---|---|---|---|---|
| NAT1 | NAT | `virbr0` | — | — | — | — (ISP-WAN Gi0/0) | WAN |
| ISP-WAN | QEMU | `vios-adventerprisek9-m.spa.159-3.m8` | 1 | 512 MB | telnet 5015 | — | WAN |
| FW-ZONA-CENTRO | QEMU | OPNsense 26.7 (plantilla `OPNsense-26.7`) | 2 | 2048 MB | telnet 5017 | Gi0/0 (trunk) | todas |
| FW-VEHICULO-01 | QEMU | OPNsense 26.7 (plantilla `OPNsense-26.7`) | 2 | 2048 MB | telnet 5023 | — | — |
| TABLET-CONDUCTOR | Docker | `alpine:latest` | — | — | telnet 5025 | — (FW-VEHICULO-01) | LAN vehículo |
| CiscoRTT-1 | QEMU | `vios-adventerprisek9-m.spa.159-3.m8` | 1 | 512 MB | telnet 5009 | **sin conexión** (reemplazado) | — |
| CiscoSW-1 | QEMU | `vios_l2-adventerprisek9-m.ssa.high_iron_20200929` | 1 | 768 MB | telnet 5011 | — | — |
| alpine-1 | Docker | `alpine:latest` | — | — | telnet 5021 | Gi0/2 | 10 |
| postgres-1 | Docker | `postgres:latest` | — | — | telnet 5018 | Gi1/1 | 20 |
| SIEM | QEMU | Ubuntu Server 22.04 (Wazuh) | 4 | 8192 MB | spice 5012 | **sin conexión** (reemplazado) | — |
| WAZUH-MANAGER | Docker | `wazuh-manager-gns3:4.14.10` | — | — | telnet 5031 | Gi2/1 | 30 |
| WAZUH-INDEXER | Docker | `wazuh-indexer-gns3:4.14.10` | — | — | telnet 5034 | Gi2/2 | 30 |
| WAZUH-DASHBOARD | Docker | `wazuh-dashboard-gns3:4.14.10` | — | — | telnet 5036 | Gi2/3 | 30 |
| odoo-1 | Docker | `odoo-gns3:latest` (ver §8, hallazgo 3) | — | — | telnet 5027 | Gi1/2 | 20 |
| SoporteTI | QEMU | Linux Mint 22.3 XFCE | 1 | 2048 MB | spice 5006 | Gi3/1 | 99 |
| Mint-1 | QEMU | Linux Mint 22.3 XFCE | 1 | 2000 MB | spice 5008 | Gi3/2 | 99 |
| WEBTERM-ADMIN | Docker | `gns3/webterm:latest` (Firefox), IP fija 10.99.0.20/25 | — | — | VNC 5902 | Gi3/3 | 99 |
| Kali-1 | QEMU | Kali Linux 2026.2 | 1 | 2048 MB | spice 5013 | **sin conexión** | — |

La VLAN de cada nodo sale del puerto donde está conectado en `eval1.gns3`, cruzado con `show vlan brief` del switch.

---

## 5. Enlaces físicos

| # | Extremo A | Puerto A | Extremo B | Puerto B |
|---|---|---|---|---|
| 1 | NAT1 | nat0 | ISP-WAN | Gi0/0 |
| 2 | ISP-WAN | Gi0/1 | FW-ZONA-CENTRO | vtnet1 |
| 3 | FW-ZONA-CENTRO | vtnet0 | CiscoSW-1 | Gi0/0 |
| 4 | ISP-WAN | Gi0/2 | FW-VEHICULO-01 | vtnet1 |
| 5 | FW-VEHICULO-01 | vtnet0 | TABLET-CONDUCTOR | eth0 |
| 6 | CiscoSW-1 | Gi0/2 | alpine-1 | eth0 |
| 7 | CiscoSW-1 | Gi1/1 | postgres-1 | eth0 |
| 8 | CiscoSW-1 | Gi2/1 | WAZUH-MANAGER | eth0 |
| 8b | CiscoSW-1 | Gi2/2 | WAZUH-INDEXER | eth0 |
| 8c | CiscoSW-1 | Gi2/3 | WAZUH-DASHBOARD | eth0 |
| 9 | CiscoSW-1 | Gi1/2 | odoo-1 | eth0 |
| 10 | CiscoSW-1 | Gi3/1 | SoporteTI | e0 |
| 11 | CiscoSW-1 | Gi3/2 | Mint-1 | e0 |
| 12 | CiscoSW-1 | Gi3/3 | WEBTERM-ADMIN | eth0 |

Los enlaces NAT1 ↔ CiscoRTT-1 y CiscoRTT-1 ↔ CiscoSW-1 se eliminaron el 2026-11-11, al poner el firewall. El enlace CiscoSW-1 Gi2/1 ↔ SIEM se eliminó el mismo día, al pasar Wazuh a Docker. Los enlaces de la red del vehículo están en [VPN_VEHICULO.md](../VPN_VEHICULO.md).

---

## 6. Router `RTR-ZONA-CENTRO` (reemplazado)

> Desde el 2026-11-11 este router está desconectado. Sus funciones (subinterfaces, DHCP, ACL, syslog) las cumple `FW-ZONA-CENTRO`; ver [VPN_VEHICULO.md §3](../VPN_VEHICULO.md). Esta sección describe la configuración que se reemplazó.

Configuración completa: [configs/RTR-ZONA-CENTRO_running-config.txt](../../configs/RTR-ZONA-CENTRO_running-config.txt).

### 6.1 Interfaces

| Interfaz | Descripción | IP | ACL de entrada | NAT |
|---|---|---|---|---|
| Gi0/0 | WAN - Conectado a nodo NAT GNS3 (ISP) | `ip address dhcp` | `EACL_WAN_IN` | outside |
| Gi0/1 | TRUNK Hacia Switch LAN | — | — | — |
| Gi0/1.10 | VLAN 10 - Administrativos y Logistica | 192.168.10.1/24 | `EACL_VLAN10_IN` | inside |
| Gi0/1.20 | VLAN 20 - Inventario y Gestion Bodegas | 192.168.20.1/24 | `EACL_VLAN20_IN` | — |
| Gi0/1.30 | VLAN 30 - Servidor SIEM y Monitoreo | 192.168.30.1/24 | `EACL_VLAN30_IN` | — |
| Gi0/1.99 | VLAN 99 - Administracion TI | 10.99.0.1/25 | `EACL_VLAN99_IN` | — |
| Gi0/2, Gi0/3 | — | — | — | shutdown |

### 6.2 Fragmentos clave

```
interface GigabitEthernet0/1.10
 encapsulation dot1Q 10
 ip address 192.168.10.1 255.255.255.0
 ip access-group EACL_VLAN10_IN in
 ip nat inside
!
ip dhcp excluded-address 192.168.10.1 192.168.10.10
ip dhcp pool POOL_VLAN10_USUARIOS
 network 192.168.10.0 255.255.255.0
 default-router 192.168.10.1
 dns-server 1.1.1.1 8.8.8.8
!
ip nat inside source list EACL_NAT_INTERNET interface GigabitEthernet0/0 overload
!
logging source-interface GigabitEthernet0/1.30
logging host 192.168.30.5
!
line vty 0 4
 access-class EACL_VTY_ACCESS in
 login local
 transport input ssh
```

### 6.3 Gestión

- SSH versión 2 en las líneas VTY 0–4, con usuario local `adminTI` (privilegio 15). Solo se aceptan conexiones desde 10.99.0.0/25 (`EACL_VTY_ACCESS`).
- `no ip http server` y `no ip http secure-server`.
- Los logs se envían por syslog al SIEM (192.168.30.5) con origen Gi0/1.30.
- Consola con contraseña de línea, privilegio 15 y sin tiempo de espera (`exec-timeout 0 0`).

### 6.4 Política de tráfico (ACLs)

Los comentarios `remark` de las ACL hablan de "criterios", probablemente los de la evaluación:

| Criterio | Regla |
|---|---|
| 1 | Aislamiento total entre VLANs |
| 2 | Las VLANs envían logs al SIEM (syslog UDP 514, agente Wazuh 1514/1515) |
| 3 | La VLAN 99 administra todo (SSH, HTTPS, SNMP, ICMP) |
| 4 | Solo la VLAN 10 sale a Internet |
| 5 | Solo la VLAN 99 puede entrar por SSH al router |

Matriz resumida, por origen:

| Origen ↓ / Destino → | Internet | VLAN 10 | VLAN 20 | VLAN 30 | VLAN 99 |
|---|---|---|---|---|---|
| **VLAN 10** | HTTP, HTTPS, DNS, NTP, ping | — | Solo 192.168.20.5 (Odoo): 8069/tcp (en `FW-ZONA-CENTRO`) | Solo 192.168.30.5: 514/udp, 1514 tcp/udp, 1515/tcp | ✗ |
| **VLAN 20** | ✗ | ✗ | — | Solo 192.168.30.5: 514/udp, 1514 tcp/udp, 1515/tcp | ✗ |
| **VLAN 30** | ✗ | Solo respuestas | Solo respuestas | — | Solo respuestas |
| **VLAN 99** | ✗ | SSH, HTTPS, SNMP, ping | SSH, HTTPS, SNMP, ping | SSH, HTTPS, SNMP, ping; Wazuh 5601, 9200, 55000, 1514, 1515, 514 | — |

Además:
- Todas las ACL de VLAN incluyen anti-spoofing: se descarta todo paquete cuyo origen pertenezca a otra VLAN, a 127/8 o a 0/8.
- Las VLAN 10 y 20 permiten DHCP (`bootpc` → `bootps`) antes del anti-spoofing.
- La VLAN 99 tiene bloqueados Telnet (23) y TFTP (69) hacia cualquier destino.
- La VLAN 30 solo puede iniciar DNS y NTP hacia su gateway 192.168.30.1.
- `EACL_WAN_IN` bloquea bogones (RFC 1918, loopback, APIPA, multicast y reservadas) y puertos peligrosos (23, 69, 135–139, 445, 1433, 3306, 3389, 5432). Desde Internet solo permite respuestas: TCP `established`, DNS, NTP y los ICMP de diagnóstico.
- Todas las ACL terminan en `deny ip any any log`.

### 6.5 Estado actual

Salida completa: [configs/RTR-ZONA-CENTRO_estado.txt](../../configs/RTR-ZONA-CENTRO_estado.txt).

- Todas las subinterfaces están `up/up`. Gi0/2 y Gi0/3 están administrativamente apagadas.
- **Gi0/0 (WAN) está `up` pero sin IP (`unassigned`)**, y no hay *gateway of last resort*. Hoy no hay salida a Internet.
- La tabla de rutas solo contiene las 4 redes conectadas.
- No hay asignaciones DHCP ni traducciones NAT activas.

---

## 7. Switch `SW-ZONA-CENTRO`

Configuración completa: [configs/SW-ZONA-CENTRO_running-config.txt](../../configs/SW-ZONA-CENTRO_running-config.txt). Estado: [configs/SW-ZONA-CENTRO_estado.txt](../../configs/SW-ZONA-CENTRO_estado.txt).

### 7.1 VLANs

| VLAN | Nombre | Puertos |
|---|---|---|
| 1 | default | — |
| 10 | ADMIN_LOGISTICA | Gi0/1, Gi0/2, Gi0/3, Gi1/0 |
| 20 | INVENTARIO_BODEGAS | Gi1/1, Gi1/2, Gi1/3, Gi2/0 |
| 30 | SERVIDORES_SIEM | Gi2/1, Gi2/2, Gi2/3, Gi3/0 |
| 99 | MGMT_TI | Gi3/1, Gi3/2, Gi3/3 |

### 7.2 Interfaces VLAN (SVI)

| Interfaz | Descripción | IP | Estado |
|---|---|---|---|
| Vlan10 | Interfaz SVI - VLAN 10 Admin/Logistica | 192.168.10.2/24 | up/up |
| Vlan20 | Interfaz SVI - VLAN 20 Inventario/Bodegas | 192.168.20.2/24 | up/up |
| Vlan30 | Interfaz SVI - VLAN 30 Servidores SIEM | 192.168.30.2/24 | up/up |
| Vlan99 | Interfaz de Gestion del Switch (SVI VLAN 99) | 10.99.0.2/25 | up/up |

- `ip default-gateway 10.99.0.1`: el switch no enruta (no tiene `ip routing`), solo usa la SVI para gestión.
- Las SVI de las VLAN 10, 20 y 30 no son necesarias para la gestión; ver §8, hallazgo 7.

### 7.3 Trunk

| Puerto | Descripción | Modo | Encapsulación | VLAN nativa | VLANs permitidas |
|---|---|---|---|---|---|
| Gi0/0 | ENLACE TRUNK HACIA RTR-LOGISTICA-01 (Gi0/1) | trunk (`on`) | 802.1Q | 1 | 10, 20, 30, 99 |

```
interface GigabitEthernet0/0
 description ENLACE TRUNK HACIA RTR-LOGISTICA-01 (Gi0/1)
 switchport trunk allowed vlan 10,20,30,99
 switchport trunk encapsulation dot1q
 switchport mode trunk
```

### 7.4 Puertos de acceso

Todos los puertos, salvo Gi0/0, son `switchport mode access` con `spanning-tree portfast edge`. Spanning-tree en modo PVST.

| Puertos | VLAN | Descripción |
|---|---|---|
| Gi0/1–Gi0/3, Gi1/0 | 10 | Puertos Acceso VLAN 10 - Admin |
| Gi1/1–Gi1/3, Gi2/0 | 20 | Puertos Acceso VLAN 20 - Inventario |
| Gi2/1–Gi2/3, Gi3/0 | 30 | Puertos Acceso VLAN 30 - Servidores SIEM |
| Gi3/1–Gi3/3 | 99 | Puertos Acceso VLAN 99 - Gestion TI |

Estado de cada puerto:

| Puerto | VLAN | Estado | Equipo |
|---|---|---|---|
| Gi0/0 | trunk | connected | FW-ZONA-CENTRO vtnet0 |
| Gi0/2 | 10 | connected | alpine-1 |
| Gi1/1 | 20 | connected | postgres-1 |
| Gi1/2 | 20 | connected | odoo-1 |
| Gi2/1 | 30 | connected | WAZUH-MANAGER |
| Gi2/2 | 30 | connected | WAZUH-INDEXER |
| Gi2/3 | 30 | connected | WAZUH-DASHBOARD |
| Gi3/1 | 99 | connected | SoporteTI |
| Gi3/2 | 99 | connected | Mint-1 |
| Gi3/3 | 99 | connected | WEBTERM-ADMIN |
| Gi0/1, Gi0/3, Gi1/3, Gi2/0, Gi3/0 | según §7.1 | notconnect | libres |
| Gi1/0 | 10 | — | libre, pero inutilizable hasta reiniciar el switch (§8, hallazgo 10) |

Puertos según los enlaces de GNS3 (2026-11-11). La VLAN de Gi1/1 sale de la configuración del switch capturada el 2026-10-24; la de Gi1/2 se comprobó en la consola el 2026-11-11 (`switchport access vlan 20`). Que `alpine-1` reciba IP del firewall por DHCP confirma que el trunk funciona.

### 7.5 Gestión

- `enable secret 5` y usuario local `adminTI` (privilegio 15, `secret 5`).
- SSH versión 2 activo (clave RSA generada, cifrados `aes128/192/256-ctr`). VTY 0–4 con `login local` y `transport input ssh`, **sin `access-class`**.
- `ip http server` e `ip http secure-server` **activados** (en el router están desactivados).
- Consola con contraseña de línea (`line con 0`, `login`).
- Syslog hacia 192.168.30.5 con origen `Vlan30`.
- `no service password-encryption`; `ip domain-name empresa.local`.

### 7.6 Configuración guardada

`show startup-config` existe (3103 bytes comprimidos en NVRAM; `service compress-config`) y su contenido es igual al de `show running-config`. Por eso no aparecía como texto en el disco del nodo. Un reinicio no pierde la configuración.

---

## 8. Hallazgos

Los puntos 3 y 4 se deducen de leer las ACL y no se probaron con tráfico real.

1. ~~**No hay salida a Internet ahora mismo.**~~ **Resuelto (2026-11-11):** `ISP-WAN` obtiene IP desde NAT1 y la VLAN 10 sale a Internet (`alpine-1` hace ping a 1.1.1.1 y resuelve DNS).
2. ~~**Odoo no está en la VLAN 20.**~~ **Resuelto (2026-11-11):** `odoo-1` pasó de Gi0/3 (VLAN 10) a **Gi1/2 (VLAN 20)**, con IP fija 192.168.20.5/24 y gateway 192.168.20.1 (archivo `etc/network/interfaces` del nodo en `project-files/docker/`). Estuvo antes en la VLAN 30 y luego en la VLAN 10.
3. ~~**Odoo no puede llegar a PostgreSQL.**~~ **Resuelto (2026-11-11):** `odoo-1` y `postgres-1` están ahora en la misma VLAN y se conectan sin pasar por el firewall. Se quitó la regla 25 de `FW-ZONA-CENTRO` (192.168.10.4 → 192.168.20.4 TCP 5432), que era una excepción al criterio 1. `postgres-1` ve la conexión desde 192.168.20.5. Otros datos:
   - Ambos contenedores tienen IP fija fuera del rango DHCP: `odoo-1` 192.168.20.5/24 y `postgres-1` 192.168.20.4/24. A `postgres-1` le faltaba el gateway 192.168.20.1, sin el cual no podía responder a otra VLAN.
   - Con Odoo en la VLAN 20, los usuarios de la VLAN 10 llegan a Odoo por una excepción puntual al criterio 1: la nueva regla 25 de `FW-ZONA-CENTRO` permite VLAN 10 → 192.168.20.5 TCP 8069 y nada más. Desde `alpine-1`, el 8069 responde `303`; el ping y el puerto 22 de Odoo y el 5432 de `postgres-1` siguen bloqueados. El vehículo también llega por la VPN, solo a ese host y al puerto 8069 ([VPN_VEHICULO.md §3.3–3.4](../VPN_VEHICULO.md)).
   - `odoo-1` tiene las variables `HOST=192.168.20.4`, `PORT=5432`, `USER=odoo` y `PASSWORD=odoo`. Su log muestra `database: odoo@192.168.20.4:5432` y `HTTP service running on 0.0.0.0:8069`.
   - `odoo-1` no arrancaba en GNS3, por un problema que ya existía y que no tiene que ver con la red. GNS3 entra al contenedor con `su odoo` y el usuario `odoo` de la imagen oficial tiene shell `nologin` (error: `This account is currently not available`). Se creó la imagen `odoo-gns3:latest` ([docker/odoo-gns3/Dockerfile](../../docker/odoo-gns3/Dockerfile)), que solo le da `/bin/bash` a ese usuario. Como GNS3 no permite cambiar la imagen de un nodo existente, se recreó `odoo-1`, que nunca había arrancado, así que no se perdieron datos. La plantilla `odoo` también usa ahora `odoo-gns3:latest`.
4. ~~**La administración desde la VLAN 99 hacia las VLAN 10 y 20 no funciona.**~~ **Resuelto con el firewall:** pf tiene estado y deja pasar las respuestas sin regla. El problema original del router IOS era este: `EACL_VLAN99_IN` permite SSH, HTTPS, SNMP y ping hacia esas VLANs, pero las respuestas se descartan: `EACL_VLAN10_IN` y `EACL_VLAN20_IN` tienen `deny ip <red> 10.99.0.0 0.0.0.127` antes de cualquier `permit ... established`. Hacia la VLAN 30 sí funciona, porque `EACL_VLAN30_IN` permite el tráfico de retorno.
5. **Kali-1 no tiene enlace.** No está en ninguna VLAN.
6. **Credenciales en claro.**
   - La contraseña de consola del router está en texto plano (`no service password-encryption`).
   - Hay credenciales en `eval1.gns3`: la nota de Wazuh en el diagrama y las variables `POSTGRES_*` del contenedor.
   - Se recogen en §10 porque es un laboratorio de estudio.
7. **El switch se puede gestionar desde cualquier VLAN, sin pasar por las ACL del router.** Tiene SVI en las VLAN 10, 20 y 30 (192.168.x.2), y las VTY no tienen `access-class`. Un equipo de esas VLANs llega al switch por capa 2, así que el router no filtra ese tráfico. Eso contradice el criterio 3 (solo la VLAN 99 administra). Además, HTTP y HTTPS están activados. Se deduce de la configuración; no se probó con tráfico real.
8. **La descripción del trunk no coincide.** Gi0/0 dice `RTR-LOGISTICA-01`, pero el router se llama `RTR-ZONA-CENTRO`.
9. **`PROYECTO.md` está desactualizado:** documenta solo 2 VLANs y asigna mal los nodos. Este documento lo reemplaza.
10. **Gi1/0 del switch queda inutilizable hasta reiniciar `SW-ZONA-CENTRO`.** Al borrar el `odoo-1` anterior, GNS3 dio un error interno (`bridge stop bridge0`) y dejó colgado el puente de uBridge de ese puerto. Por eso el nuevo `odoo-1` se conectó primero a **Gi0/3**, que también es de la VLAN 10 (hoy está en Gi1/2, hallazgo 2). Reiniciar el switch libera Gi1/0; su configuración está guardada en NVRAM.

---

## 9. Acceso

Comandos útiles por nodo: [HOJA_TRAMPA.md](../HOJA_TRAMPA.md).

| Equipo | Acceso |
|---|---|
| FW-ZONA-CENTRO | `telnet 127.0.0.1 5017` (consola serie) o, desde la VLAN 99, SSH y `https://10.99.0.1` con el usuario `root` |
| FW-VEHICULO-01 | `telnet 127.0.0.1 5023` (consola serie) |
| ISP-WAN | `telnet 127.0.0.1 5015` |
| Router (reemplazado) | `telnet 127.0.0.1 5009` (consola) |
| Switch | `telnet 127.0.0.1 5011` (consola y `enable` con contraseña, ver §10) o SSH a 10.99.0.2 con el usuario `adminTI` |
| Wazuh | Desde la VLAN 99 (`WEBTERM-ADMIN`, VNC 5902, es lo más liviano): dashboard `https://192.168.30.7:5601`, API `https://192.168.30.5:55000`. Desde el host: `telnet 127.0.0.1 5031/5034/5036` o `docker exec` (ver [WAZUH_DOCKER.md](../WAZUH_DOCKER.md)) |

---

## 10. Credenciales

> Laboratorio de estudio: las credenciales se guardan en texto plano a propósito. No reutilizar en entornos reales.

| Equipo / servicio | Usuario | Contraseña | Fuente |
|---|---|---|---|
| Router: consola (`line con 0`) | — | `test123` (entra directo con privilegio 15) | running-config |
| Router: `enable` | — | `test123` | `enable secret 9`; hash verificado |
| Router: SSH | `adminTI` (privilegio 15) | `test123` | `username ... secret 9`; hash verificado |
| Switch: consola (contraseña de línea) | — | `test123` | Probada en la consola |
| Switch: `enable` | — | `test123` | Probada en la consola; hash `enable secret 5` verificado |
| Switch: SSH | `adminTI` (privilegio 15) | `test123` | `username ... secret 5`; hash verificado |
| Wazuh (dashboard e indexer) | `admin` | `g0XCwEOvqhmcu7j4zC*RF6UMQfed9wBD` | Nota del diagrama en `eval1.gns3`; se mantuvo en Docker y se probó (2026-11-11) |
| Wazuh (API) | `wazuh-wui` | `MyS3cr37P450r.*-` | Valor por defecto de `wazuh-docker`, ver [WAZUH_DOCKER.md §5](../WAZUH_DOCKER.md) |
| PostgreSQL (`postgres-1`) | `odoo` | `odoo` (base de datos `postgres`) | `POSTGRES_PASSWORD` en `eval1.gns3`; hash SCRAM del rol verificado |
| OPNsense (imagen base de la plantilla) | `root` | `opnsense` (de fábrica) | Instalación del 2026-11-11, ver [OPNSENSE_PLANTILLA.md](../OPNSENSE_PLANTILLA.md) |
| `FW-ZONA-CENTRO` y `FW-VEHICULO-01` | `root` | `test123` | Probada en la consola serie |
| PSK del túnel IPsec | — | `test123` | [VPN_VEHICULO.md §7](../VPN_VEHICULO.md) |

Verificación (2026-10-24): los hashes `secret 9` (scrypt) del router se recalcularon con `test123` y coinciden. En el switch, `test123` se probó en la consola y los hashes `secret 5` (MD5) coinciden. El rol `odoo` de PostgreSQL **no** usa `test123`; su contraseña es `odoo`.

---

## 11. Archivos

| Archivo | Contenido |
|---|---|
| `eval1.gns3` | Topología GNS3 (JSON) |
| [configs/RTR-ZONA-CENTRO_running-config.txt](../../configs/RTR-ZONA-CENTRO_running-config.txt) | `show running-config` del router |
| [configs/RTR-ZONA-CENTRO_estado.txt](../../configs/RTR-ZONA-CENTRO_estado.txt) | Interfaces, rutas, DHCP y NAT del router |
| [configs/SW-ZONA-CENTRO_running-config.txt](../../configs/SW-ZONA-CENTRO_running-config.txt) | `show running-config` del switch |
| [configs/SW-ZONA-CENTRO_estado.txt](../../configs/SW-ZONA-CENTRO_estado.txt) | VLANs, trunk y puertos del switch |
| [OPNSENSE_PLANTILLA.md](../OPNSENSE_PLANTILLA.md) | Instalación de la imagen base de OPNsense 26.7 y ajustes de la plantilla QEMU en GNS3 |
| [VPN_VEHICULO.md](../VPN_VEHICULO.md) | Extensión VPN: ISP-WAN, firewalls OPNsense, túnel IPsec y pruebas |
| [configs/ISP-WAN_running-config.txt](../../configs/ISP-WAN_running-config.txt) | `show running-config` de ISP-WAN |
| [configs/opnsense/](../../configs/opnsense/) | Scripts PHP con los que se configuraron los dos firewalls |
| [docker/odoo-gns3/Dockerfile](../../docker/odoo-gns3/Dockerfile) | Imagen de Odoo compatible con GNS3 (`docker build -t odoo-gns3:latest docker/odoo-gns3`) |
| [docs/img/vpn-vehiculo/](../img/vpn-vehiculo/) | Capturas de las consolas de los firewalls |
| [odd/tasks/vpn-vehiculo.md](../../odd/tasks/vpn-vehiculo.md) | Especificación y bitácora de la extensión VPN |
| [docs/img/opnsense-instalacion/](../img/opnsense-instalacion/) | Capturas de la instalación de OPNsense |
