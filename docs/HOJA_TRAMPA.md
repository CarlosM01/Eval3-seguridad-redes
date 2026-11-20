# Hoja trampa — `eval1`

Comandos útiles por nodo. Detalle en [INFRAESTRUCTURA.md](linea-base/INFRAESTRUCTURA.md) y [VPN_VEHICULO.md](VPN_VEHICULO.md). Credenciales: [INFRAESTRUCTURA.md §10](linea-base/INFRAESTRUCTURA.md) y [VPN_VEHICULO.md §7](VPN_VEHICULO.md) (casi todo es `test123`).

---

## Acceso desde el host

Con la IP actualizada por el propietario el 2026-11-18, el servidor GNS3 escucha en la red ZeroTier del curso (`10.163.179.125:3080`) para que los compañeros se conecten con su cliente. Las consolas también escuchan en esa IP, no en `127.0.0.1`. Al abrir el proyecto los nodos **no** arrancan solos (`auto_start = False`): arrancar primero `CiscoSW-1`, `ISP-WAN` y los dos firewalls, y unos 2 minutos después el resto (`Kali` y `SoporteTI` quedan apagados).

| Nodo              | Consola                     | IP                                                    |
| ----------------- | --------------------------- | ----------------------------------------------------- |
| ISP-WAN           | `telnet 10.163.179.125 5015` | 203.0.113.1, 100.64.20.1                              |
| FW-ZONA-CENTRO    | `telnet 10.163.179.125 5017` | 203.0.113.2, 192.168.{10,20,30}.1, 10.99.0.1          |
| FW-VEHICULO-01    | `telnet 10.163.179.125 5023` | 100.64.20.x (DHCP), 172.16.50.1                       |
| TABLET-CONDUCTOR  | `telnet 10.163.179.125 5025` | 172.16.50.10                                          |
| WEBTERM-VEHICULO  | VNC 5901                    | 172.16.50.20                                          |
| WEBTERM-ADMIN     | VNC 5902                    | 10.99.0.20 (VLAN 99)                                  |
| CiscoSW-1         | `telnet 10.163.179.125 5011` | 10.99.0.2                                             |
| alpine-1          | `telnet 10.163.179.125 5021` | VLAN 10, DHCP                                         |
| odoo-1            | `telnet 10.163.179.125 5027` | 192.168.20.5 (VLAN 20)                                |
| postgres-1        | `telnet 10.163.179.125 5018` | 192.168.20.4                                          |
| WAZUH-MANAGER     | `telnet 10.163.179.125 5031` | 192.168.30.5                                          |
| WAZUH-INDEXER     | `telnet 10.163.179.125 5034` | 192.168.30.6                                          |
| WAZUH-DASHBOARD   | `telnet 10.163.179.125 5036` | 192.168.30.7 (HTTPS 5601)                             |
| SIEM (VM antigua) | spice 5012                  | apagada, sin enlaces                                  |
| PKI-CA            | `telnet 10.163.179.125 5008` | 10.99.0.10 (VLAN 99): CRL y CA por HTTP 80, OCSP 2560 |
| CA-RAIZ           | `telnet 10.163.179.125 5010` | sin red (CA raíz fuera de línea)                      |
| SoporteTI         | spice 5006                  | VLAN 99                                               |
| CiscoRTT-1        | `telnet 10.163.179.125 5009` | apagado, sin enlaces                                  |

```bash
# Shell de un contenedor Docker
docker exec -it GNS3.<nodo>.54ea9572-79a3-48cc-9bb4-8c652368f315 sh

# Consola gráfica (VNC), independiente de la terminal
setsid remote-viewer vnc://10.163.179.125:5901 &   # WEBTERM-VEHICULO
setsid remote-viewer vnc://10.163.179.125:5902 &   # WEBTERM-ADMIN
```

Si el servidor de GNS3 se reinicia con los nodos encendidos, sus procesos QEMU y Xtigervnc quedan huérfanos: bloquean los discos ("Could not rebase the image") y los puertos VNC (GNS3 asigna otros). Guardar la config de los Cisco (`write memory`), terminar esos procesos y volver a arrancar. Si `odoo-1` arranca antes que el switch, se cierra porque no llega a `postgres-1`: reiniciarlo.

---

## Túnel VPN: prueba rápida

1. `FW-VEHICULO-01` → `swanctl --list-sas` muestra el túnel `ESTABLISHED`.
2. `TABLET-CONDUCTOR` → `ping 192.168.10.230` (alpine-1, VLAN 10) responde.
3. `WEBTERM-VEHICULO` → Firefox abre `http://192.168.20.5:8069` (Odoo, único host de la VLAN 20 en el túnel, solo puerto 8069).
4. `TABLET-CONDUCTOR` → `ping 192.168.20.1` **falla**: el resto de la VLAN 20 queda fuera del túnel.

---

## ISP-WAN (IOSv, sin contraseña)

```
show ip interface brief
show ip nat translations          CGNAT: 100.64.20.x -> 198.51.100.10
show ip nat statistics
show ip dhcp binding              IP móvil entregada al vehículo
show ip route
clear ip nat translation *        borra la NAT: el túnel se renegocia
```

## FW-ZONA-CENTRO (OPNsense, `root`, opción 8 = shell)

```
swanctl --list-sas                estado del túnel
swanctl --list-conns              configuración IPsec cargada
tcpdump -ni enc0                  tráfico dentro del túnel
tcpdump -ni vtnet1 udp port 4500  ESP encapsulado (NAT-T)
pfctl -sr | less                  reglas cargadas
pfctl -ss | grep 172.16.50        estados de la VPN
tail -f /var/log/filter/latest.log
configctl filter reload           recarga reglas
grep 'match,block' /var/log/filter/latest.log | tail      bloqueos registrados (R-99 y otros)
pfctl -sr | grep <etiqueta>       qué regla tiene esa etiqueta del log
crontab -l | grep schedules       tarea que reevalúa HORARIO_DESPACHO cada 15 min
```

Web: `https://10.99.0.1`, solo desde la VLAN 99 (WEBTERM-ADMIN, SoporteTI o Mint-1). Log por regla: *Firewall → Log Files → Live View*, filtrando por `R-01`, `R-99`, etc. Las reglas y el horario de la U3 están en [U3_SEGURIDAD_PERIMETRAL.md](U3_SEGURIDAD_PERIMETRAL.md).

## FW-VEHICULO-01 (OPNsense, `root`, opción 8 = shell)

```
swanctl --list-sas                estado del túnel
ifconfig vtnet1                   IP de la red móvil (DHCP)
ping -S 172.16.50.1 192.168.10.1  prueba desde la LAN del vehículo
tcpdump -ni enc0
tcpdump -ni vtnet1 udp port 4500
```

Web: `https://172.16.50.1`, desde WEBTERM-VEHICULO.

## TABLET-CONDUCTOR (alpine)

```
ping 192.168.10.230               alpine-1 (VLAN 10) por el túnel
traceroute -n 192.168.10.230
nc -zv 192.168.20.5 8069          Odoo (VLAN 20) por el túnel
ping 192.168.20.5                 debe fallar: a Odoo solo pasa el 8069
ping 192.168.20.1                 debe fallar
```

## WEBTERM-VEHICULO (Firefox por VNC)

| URL | Qué es |
|---|---|
| `http://192.168.20.5:8069` | Odoo de la sede, por el túnel |
| `https://172.16.50.1` | OPNsense del vehículo |

Si la consola no abre con doble clic en GNS3: Preferences → General → VNC → `remote-viewer vnc://{host}:{port}`.

## WEBTERM-ADMIN (Firefox por VNC, VLAN 99)

| URL                         | Qué es                              |
| --------------------------- | ----------------------------------- |
| `https://192.168.30.7:5601` | Dashboard de Wazuh, usuario `admin` |
| `https://10.99.0.1`         | OPNsense de la sede                 |

La primera vez Firefox avisa del certificado autofirmado: *Advanced* → *Proceed*.

Ojo: la IP del OPNsense es `10.99.0.1` (VLAN 99), no `10.0.99.1`. Con la IP invertida Firefox da *timeout*.
Captura del login: `docs/img/wazuh-docker/02-webterm-admin-opnsense-login.png`.

---

## CiscoSW-1 / SW-ZONA-CENTRO (IOSv-L2)

```
enable
show vlan brief
show interfaces trunk
show interfaces status
show mac address-table
show ip interface brief
```

SSH desde la VLAN 99: `ssh adminTI@10.99.0.2`.

## alpine-1 (VLAN 10)

```
udhcpc -i eth0                    renueva DHCP
ip -4 addr show eth0
ping 172.16.50.10                 tablet por el túnel
ping 1.1.1.1                      salida a Internet
nslookup example.com
nc -zv 192.168.20.5 8069          Odoo (VLAN 20), regla 25
nc -zv 192.168.20.4 5432          debe fallar: PostgreSQL no se expone
```

## odoo-1 (VLAN 20, 192.168.20.5)

```
pg_isready -h 192.168.20.4 -p 5432        llega a la base (misma VLAN)
curl -sI http://127.0.0.1:8069/web/login
```

Desde el host: `docker logs -f GNS3.odoo-1.54ea9572-79a3-48cc-9bb4-8c652368f315`.

## postgres-1

```
pg_isready
psql -U odoo -d postgres -c '\l'
psql -U odoo -d postgres -c 'select client_addr from pg_stat_activity;'
```

## Wazuh (Docker)

Detalle en [WAZUH_DOCKER.md](WAZUH_DOCKER.md). Desde el host, con `S=54ea9572-79a3-48cc-9bb4-8c652368f315`:

```bash
# Manager: estado de los demonios, alertas en vivo, agentes
docker exec GNS3.WAZUH-MANAGER.$S /var/ossec/bin/wazuh-control status
docker exec GNS3.WAZUH-MANAGER.$S tail -f /var/ossec/logs/alerts/alerts.log
docker exec GNS3.WAZUH-MANAGER.$S /var/ossec/bin/agent_control -l
docker exec GNS3.WAZUH-MANAGER.$S filebeat test output

# Indexer: salud e índices
docker exec GNS3.WAZUH-INDEXER.$S curl -sk -u 'admin:g0XCwEOvqhmcu7j4zC*RF6UMQfed9wBD' https://192.168.30.6:9200/_cluster/health
docker exec GNS3.WAZUH-INDEXER.$S curl -sk -u 'admin:g0XCwEOvqhmcu7j4zC*RF6UMQfed9wBD' 'https://192.168.30.6:9200/_cat/indices/wazuh-*'

# Dashboard: log
docker logs --tail 20 GNS3.WAZUH-DASHBOARD.$S

# Reconstruir las imágenes
cd docker/wazuh-gns3 && ./build.sh
```

Dentro de los contenedores no hay `ip` ni `ping`: usa `/gns3/bin/busybox ip addr` y `/gns3/bin/busybox ping`.

Generar una alerta de prueba desde `FW-ZONA-CENTRO` (shell, opción 8):

```
logger -p auth.warning -t sshd 'Failed password for invalid user prueba from 10.99.0.50 port 40022 ssh2'
```

Dashboard: `https://192.168.30.7:5601`, desde la VLAN 99 (lo más rápido: WEBTERM-ADMIN, VNC 5902). Usuario `admin`.

## PKI-CA y CA-RAIZ (Docker, `pki-ca-gns3`)

Detalle en [PKI.md](PKI.md).

```bash
S=54ea9572-79a3-48cc-9bb4-8c652368f315
# Emitir con la CSR hecha en el equipo (copiar la CSR por el host)
docker exec -i GNS3.PKI-CA.$S sh -c 'cat > /tmp/equipo.csr' < equipo.csr
docker exec GNS3.PKI-CA.$S pki-emitir servidor wms.empresa.local --csr /tmp/equipo.csr --san IP:192.168.20.5
# Revocar y ver la CRL
docker exec GNS3.PKI-CA.$S pki-revocar wms.empresa.local superseded
curl -s http://10.99.0.10/crl/emisora.crl | openssl crl -inform DER -noout -text   # desde la VLAN 99
# Consultar OCSP (desde cualquier VLAN interna)
openssl ocsp -issuer emisora.pem -cert equipo.pem -url http://10.99.0.10:2560 -CAfile cadena.pem
# Servicios y logs
docker exec GNS3.PKI-CA.$S sh -c 'netstat -ltn; tail /var/log/pki/*.log'
```

Para aplicar una imagen reconstruida, **parar y arrancar** el nodo después de forzar la recreación (cambiar `extra_hosts` y dejarlo como estaba). El `reload` de la API dejó el contenedor sin `eth0`.

## SoporteTI (VLAN 99, administración)

```
ssh root@10.99.0.1                FW-ZONA-CENTRO
ssh adminTI@10.99.0.2             switch
```

Firefox: `https://10.99.0.1` (OPNsense de la sede), `https://192.168.30.7:5601` (Wazuh).

## CiscoRTT-1

Reemplazado por FW-ZONA-CENTRO. Su configuración original está en [configs/RTR-ZONA-CENTRO_running-config.txt](../configs/RTR-ZONA-CENTRO_running-config.txt).
