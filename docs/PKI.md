# PKI del laboratorio `eval1`

La empresa tiene su propia infraestructura de clave pública (PKI). Todos los certificados del laboratorio (VPN, WMS, syslog, AS2, usuarios remotos) los emite la CA emisora `PKI-CA` y se validan contra la CA raíz `CA-RAIZ`. Las claves de las dos CA están en tokens PKCS#11 y no se pueden extraer.

Requisito: [informe.pdf](informe.pdf), POL-04 y Cuadro D. Plan y bitácora: [../odd/tasks/informe-hardening.md](../odd/tasks/informe-hardening.md), tareas T1.1 y T1.2.

## Uso rápido

Desde el anfitrión (`S=54ea9572-79a3-48cc-9bb4-8c652368f315`):

1. El equipo genera su clave y una CSR (la clave nunca sale del equipo).
2. Copiar la CSR a `PKI-CA`: `docker exec -i GNS3.PKI-CA.$S sh -c 'cat > /tmp/x.csr' < x.csr`
3. Emitir: `docker exec GNS3.PKI-CA.$S pki-emitir <perfil> <nombre> --csr /tmp/x.csr [--san IP:...]`
4. El equipo descarga su certificado y la cadena:
   - `http://10.99.0.10/certs/<nombre>.pem`
   - `http://10.99.0.10/ca/cadena.pem`

Más comandos en [HOJA_TRAMPA.md](HOJA_TRAMPA.md).

---

## 1. Jerarquía

```
CA-RAIZ  "Empresa CA Raiz"        RSA-3072, 10 años, sin red, clave en token "raiz"
   └── PKI-CA  "Empresa CA Emisora"   RSA-3072, 5 años, VLAN 99, clave en token "emisora"
          ├── "Empresa OCSP Emisora"   ECDSA P-256, 1 año, firma las respuestas OCSP
          └── certificados finales     (perfiles de la sección 3)
```

| Certificado | Algoritmo | Vigencia | Serie / huella | Dónde está la clave |
|---|---|---|---|---|
| `CN=Empresa CA Raiz` | RSA-3072, SHA-256 | 2026-11-13 → 2036-11-10 | SHA-256 `32:53:E0:8F:…:40:E0:8D` | `CA-RAIZ`, token `raiz` |
| `CN=Empresa CA Emisora` (`pathlen:0`) | RSA-3072, SHA-256 | 2026-11-13 → 2031-11-12 | serie `4BC221DD…FA18292A` | `PKI-CA`, token `emisora`, objeto `emisora` |
| `CN=Empresa OCSP Emisora` (EKU `OCSPSigning`, `noCheck`) | ECDSA P-256 | 2026-11-13 → 2027-11-13 | — | `PKI-CA`, token `emisora`, objeto `ocsp` |

Todos los certificados llevan `O=Empresa Logistica`.

## 2. Nodos

| Nodo | Red | Servicios | Datos persistentes |
|---|---|---|---|
| `CA-RAIZ` | Ninguna (0 adaptadores) | Ninguno | `/srv/ca/raiz`, `/var/lib/softhsm/tokens` |
| `PKI-CA` | `SW-ZONA-CENTRO` Gi0/1, VLAN 99, 10.99.0.10/25, gw 10.99.0.1 | HTTP 80 (CA y CRL), OCSP 2560, cron diario de la CRL | `/srv/ca/emisora`, `/var/lib/softhsm/tokens` |

Imagen común: [../docker/pki-ca-gns3/](../docker/pki-ca-gns3/) (Alpine 3.24, OpenSSL 3.5, SoftHSM2 2.7, proveedor PKCS#11 de libp11, lighttpd). Al arrancar, el contenedor ejecuta `pki-arranque`: en `PKI-CA` regenera la CRL y levanta los servicios; en `CA-RAIZ` no levanta nada.

### Publicación (`http://10.99.0.10`)

| Ruta | Contenido |
|---|---|
| `/ca/raiz.pem`, `/ca/emisora.pem`, `/ca/cadena.pem` | Certificados de las CA (`cadena.pem` = emisora + raíz) |
| `/crl/emisora.crl` | CRL de la emisora (DER), válida 7 días |
| `/crl/raiz.crl` | CRL de la raíz (DER), válida 365 días |
| `/certs/<nombre>.pem` | Certificados finales vigentes |
| `http://10.99.0.10:2560` | Respondedor OCSP |

Los certificados finales incluyen estas URL (CRL Distribution Points y Authority Information Access), así que los clientes las encuentran solos.

### Acceso por red (regla 95 de `FW-ZONA-CENTRO`)

| Origen | Puertos hacia 10.99.0.10 | Cómo |
|---|---|---|
| VLAN 99 | todos | mismo segmento, no pasa por el firewall |
| VLAN 10, 20 y 30 | TCP 80 y 2560 | regla 95, alias `PKI_CA` y `PUERTOS_PKI` ([../configs/opnsense/pki-regla.php](../configs/opnsense/pki-regla.php)) |
| Túnel IPsec, WAN | ninguno | sin regla (bloqueo por defecto) |

HTTP en claro es aceptable aquí: CRL y respuestas OCSP van firmadas y no son secretas. Es la excepción a POL-07 que prevé el plan.

## 3. Perfiles de certificado final

| Perfil | Clave exigida | Vigencia | Uso de clave / EKU | SAN por defecto | Para |
|---|---|---|---|---|---|
| `servidor` | ECDSA P-256 | 1 año | `digitalSignature` / `serverAuth` | `DNS:<nombre>` | WMS (`wms`), SIEM (`siem`) |
| `equipo` | ECDSA P-256 | 1 año | `digitalSignature` / `serverAuth`, `clientAuth`, `ipsecIKE` | `DNS:<nombre>` | Firewalls (`fw-centro`, `vehiculo-01`), relé syslog |
| `usuario` | ECDSA P-256 | 1 año | `digitalSignature` / `clientAuth`, `ipsecIKE` | `email:<nombre>` | Usuarios VPN remotos (C4) |
| `socio` | RSA-3072 | 2 años | `digitalSignature`, `keyEncipherment` / `serverAuth`, `clientAuth`, `emailProtection` | `DNS:<nombre>` | Pasarela AS2 y socios (C3a) |

`pki-emitir` impone el sujeto (`O=Empresa Logistica, CN=<nombre>`) y el SAN; no copia nada de la CSR. También rechaza una CSR cuyo tipo o tamaño de clave no corresponde al perfil, y un nombre que ya tiene un certificado vigente.

## 4. Certificados finales previstos

Cada certificado se emite en la tarea que configura su equipo, para que la clave se genere en el propio equipo (CSR) y no en la CA.

| Nombre (CN y SAN) | Perfil | Equipo | Tarea | Estado |
|---|---|---|---|---|
| `fw-centro.empresa.local` + `IP:203.0.113.2`, `IP:10.99.0.1` | equipo | `FW-ZONA-CENTRO` (IPsec y servidor OpenVPN; web y syslog TLS pendientes) | T1.3, T2.1 | Emitido 2026-11-20 (serie `…DB374`) |
| `vehiculo-01.empresa.local` | equipo | `FW-VEHICULO-01` (IPsec; web pendiente) | T1.3, T2.1 | Emitido 2026-11-20 (serie `…DB375`) |
| `wms.empresa.local` + `IP:192.168.20.5` | servidor | `odoo-1` (nginx) | T2.2 | Pendiente |
| `siem.empresa.local` + `IP:192.168.30.5` | servidor | `WAZUH-MANAGER` (rsyslog TLS) | T3.1 | Pendiente |
| `rele-syslog.empresa.local` | equipo | `RELE-SYSLOG` | T3.3 | Pendiente |
| `supervisor1@supervisores.empresa.local` | usuario | `REMOTO-SUPERVISOR` (cliente OpenVPN) | T5.2 | Emitido 2026-11-20 (serie `…DB373`) |
| `admin1@admins.empresa.local` | usuario | `REMOTO-ADMIN` | T5.2 | Pendiente |
| `as2.empresa.local` + `IP:203.0.113.2`, `IP:192.168.40.10` | socio | `B2B-DMZ` (nginx HTTPS; AS2 pendiente) | T5.3 | Emitido 2026-11-20 (serie `…DB372`) |
| `as2.socio.example` | socio | `SOCIO-B2B` | T5.3 | Pendiente |

Historial: `prueba-ocsp.empresa.local` (serie `70F754D5…3A460534`) se emitió y revocó el 2026-11-13 para probar OCSP y la CRL.

**Reconstrucción del 2026-11-20.** Los volúmenes de `CA-RAIZ` y `PKI-CA` aparecieron vacíos (desde el 17-11). La PKI se regeneró con los mismos scripts (`pki-raiz-init`, `pki-emisora-csr`, `pki-raiz-firmar`, `pki-emisora-instalar`, `pki-ocsp-init`). Huellas nuevas (SHA-256): raíz `FB:B9:3F:39:C0:DE:2C:37:43:51:04:08:B1:F7:86:B0:40:E4:A7:B3:27:F4:CA:D1:CB:94:17:F6:3F:7B:65:E1` (vence 2036-11-17); emisora `DD:03:1A:8B:B9:A0:CC:5D:6D:8B:FA:5C:A6:15:9A:36:4A:73:2C:40:6A:B0:4F:8C:79:3E:8F:33:6F:0F:99:03` (vence 2031-11-19). Los certificados emitidos antes de esa fecha ya no son válidos. Detalle en [U3_SUMATIVA.md §8](U3_SUMATIVA.md).

## 5. Procedimientos

### Emitir

| Caso | Comando en `PKI-CA` |
|---|---|
| El equipo genera su CSR (lo normal) | `pki-emitir <perfil> <nombre> --csr /tmp/x.csr [--san "IP:a.b.c.d,DNS:otro"]` |
| El equipo no puede generar una CSR (excepción) | `pki-emitir <perfil> <nombre>`: la clave se genera en memoria y sale en `/srv/ca/emisora/salida/<nombre>.p12`, con la contraseña en `<nombre>.p12.pass`. Copiar ambos al equipo y **borrarlos** de la CA |

Para crear la CSR en un equipo con OpenSSL:

```bash
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out equipo.key   # socio: -algorithm RSA -pkeyopt rsa_keygen_bits:3072
openssl req -new -key equipo.key -subj "/CN=equipo" -out equipo.csr
```

### Revocar

`pki-revocar <nombre> [motivo]`. Motivos: `unspecified`, `keyCompromise`, `affiliationChanged`, `superseded`, `cessationOfOperation`. Publica una CRL nueva al momento, y OCSP responde `revoked` desde la consulta siguiente. Para renovar un certificado, revocarlo con `superseded` y emitir de nuevo.

### CRL

La CRL de la emisora se regenera al arrancar `PKI-CA`, cada día a las 03:17 (cron) y en cada revocación. Su vigencia es de 7 días: si `PKI-CA` queda apagada más de 7 días, los clientes que exigen CRL dejan de aceptar certificados hasta que vuelva a arrancar. A mano: `pki-crl`.

### Firmar o renovar la CA emisora (ceremonia con la raíz)

1. En `PKI-CA`: `pki-emisora-csr`, que deja la CSR en `/srv/ca/emisora/emisora.csr`.
2. Pasar la CSR a `CA-RAIZ` por el anfitrión (`docker exec … cat`). `docker cp` no ve estos volúmenes de GNS3.
3. En `CA-RAIZ`: `pki-raiz-firmar /tmp/emisora.csr`. Deja `emisora.pem`, `raiz.pem` y `raiz.crl` en `/srv/ca/raiz/salida/`.
4. Volver a pasar los tres archivos a `PKI-CA` y ejecutar `pki-emisora-instalar emisora.pem raiz.pem raiz.crl`. El script comprueba la cadena y que el certificado corresponda a la clave del token.

La CRL de la raíz (`pki-raiz-crl` en `CA-RAIZ`) vence el 2027-11-13: hay que regenerarla y copiarla a `PKI-CA` antes de esa fecha.

### Renovar el certificado OCSP (anual, vence 2027-11-13)

En `PKI-CA`: borrar `/srv/ca/emisora/ocsp.pem`, borrar los objetos `ocsp` del token (`pkcs11-tool --delete-object`), ejecutar `pki-ocsp-init` y reiniciar el nodo.

## 6. Protección de las claves

- Las claves de las CA y de OCSP se generan dentro del token con `--sensitive`. SoftHSM2 las marca `always sensitive, never extractable`, y en `/srv/ca` no hay ningún archivo de clave privada.
- OpenSSL firma a través del proveedor PKCS#11 (`pkcs11prov`), con URI `pkcs11:token=…;object=…;type=private?pin-source=file:…`. El PIN se lee de un archivo y no aparece en la lista de procesos.
- Los PIN de usuario y de SO de cada token son aleatorios. Están en `/srv/ca/<ca>/private/pin` y `so-pin`, con permisos 0600.
- `CA-RAIZ` no tiene interfaces de red. Solo intercambia archivos con `PKI-CA` a través del anfitrión.

Lo que este diseño **no** cubre en un laboratorio virtual (HSM por software, raíz en el mismo equipo físico) está en [INFORME_MEJORAS.md §4](INFORME_MEJORAS.md).

## 7. Detalles técnicos que costaron

| Problema | Solución |
|---|---|
| `openssl ocsp` atiende una conexión a la vez y se queda girando en una conexión vacía; el respondedor dejó de contestar tras un `nc -z` | El respondedor escucha en 127.0.0.1:2561 (también abierto en VLAN 99) y lighttpd recibe las conexiones en 2560 y le pasa solo peticiones completas. Con conexiones vacías abiertas, OCSP sigue respondiendo |
| `openssl ocsp` relee `index.txt` recién después de atender la siguiente petición | `pki-publicar` hace una consulta local después de cada emisión o revocación |
| `-multi` (procesos hijos) falla en el contenedor (`RAND_poll() failed`) | Se descartó. Un proceso detrás del proxy basta para el laboratorio |
| libp11 escribe mensajes de depuración en la salida estándar | Los scripts escriben claves y CSR con `-out`, nunca por la salida estándar |
| `docker cp` no ve `/srv/ca` (GNS3 monta los volúmenes dentro del contenedor) | Copiar con `docker exec <nodo> cat … > archivo` |
