# Control local de GNS3 y ZeroTier

Ejecuta `scripts/labctl.py` desde la raíz del repositorio, como tu usuario habitual, **sin anteponer `sudo`**. El script solicita sudo solo para ZeroTier y UFW. Requiere Python 3, systemd de usuario, GNS3, ZeroTier, UFW activo, `ip` y `ss`.

```bash
./scripts/labctl.py up       # ZeroTier → reglas UFW → servidor GNS3
./scripts/labctl.py status   # Servicios, direcciones, escucha TCP y reglas UFW
./scripts/labctl.py down     # Servidor → bloqueo UFW → detener ZeroTier
```

La IP prevista es `10.163.179.125`; la interfaz informada es `zttqhwfn5o`. El script detecta la interfaz por su IP y espera hasta 30 segundos tras iniciar ZeroTier. No se une a redes nuevas ni cambia la IP.

## Acciones separadas

| Acción | Resultado |
|---|---|
| `network-start` | Inicia ZeroTier y espera la IP prevista |
| `network-stop` | Detiene **todo** el servicio ZeroTier y sus redes; rechaza si el GNS3 gestionado sigue activo |
| `server-start` | Inicia GNS3 en la unidad de usuario `gns3-eval1.service`; requiere ZeroTier, no abre puertos |
| `server-stop` | Detiene solo esa unidad, nunca busca y mata otros procesos GNS3 |
| `ports-open` | Permite nuevas conexiones GNS3 por ZeroTier |
| `ports-close` | Bloquea nuevas conexiones GNS3 por ZeroTier, aunque existan permisos generales anteriores |
| `logs` | Muestra las últimas 60 líneas del registro de la unidad |
| `--help` | Lista las acciones y opciones |

Antes de apagar el servidor, guarda las configuraciones y detén los nodos desde GNS3. La unidad envía SIGTERM al servidor y permite hasta 120 segundos de cierre; después systemd puede terminar los procesos restantes. No se controla una instancia iniciada manualmente o por la interfaz gráfica: ciérrala desde donde la arrancaste. `server-start` rechaza un puerto 3080 ocupado.

## Puertos y alcance

| Tráfico | Regla gestionada |
|---|---|
| API GNS3 | TCP 3080 |
| Consolas, VNC y SPICE | TCP 5000–10000; incluye VNC 5900–5950 |
| Enlaces GNS3 | UDP 10000–16260 y 16263–20000 |

Se excluyen **16261 y 16262**, reservados para Project Zomboid. Si GNS3 asigna alguno de esos puertos UDP a un enlace remoto, el script no lo habilita: reasigna ese enlace desde GNS3. El rango configurado de GNS3 todavía es 10000–20000; el script no modifica esa configuración.

Las reglas solo coinciden con la **IP IPv4 indicada y la interfaz ZeroTier**. No abren servicios en Wi-Fi/Ethernet ni gestionan IPv6. No modifican las reglas existentes de 5173, Project Zomboid, ZeroTier UDP 9993, ni los permisos generales ya presentes. UDP 9993 debe estar permitido para el transporte ZeroTier; ya lo está en las reglas informadas por el propietario.

`ports-close` no interrumpe conexiones establecidas, ni bloquea otras IP/interfaces o tráfico reenviado a contenedores. Para terminar el acceso de esta sesión usa `down`. La API está documentada sin autenticación: `ports-open` permite acceso a los miembros que llegan por esa red ZeroTier.

## Comprobación previa y fallos

```bash
./scripts/labctl.py --dry-run --interface zttqhwfn5o up
./scripts/labctl.py --dry-run --interface zttqhwfn5o down
./scripts/labctl.py --interface zttqhwfn5o ports-close  # también con ZeroTier apagado
```

`--dry-run` muestra los cambios propuestos y hace consultas locales; puede pedir sudo para leer UFW, pero no inicia servicios ni cambia reglas. La configuración local debe coincidir con la IP, el puerto 3080, las consolas 5000–10000 y `auto_start = False`; nunca se sobrescribe.

Las reglas propias llevan comentarios `eval1-gns3-<protocolo>-<acción>`. Solo se borran por especificación completa y comentario. Una regla idéntica ajena produce error, no se adopta ni elimina. Las reglas se insertan al principio y se comprueba su presencia. La transición entre permitir y bloquear **no es atómica**: se elimina la regla propia anterior antes de insertar la nueva. Si la inserción falla se intenta restaurar la anterior; cualquier fallo se informa con salida distinta de cero. Un fallo entre TCP y UDP puede dejar un cambio parcial: revisa `status` y repite `ports-close` cuando se resuelva.

No edites UFW simultáneamente. Las invocaciones de este script se serializan con un bloqueo local; otras herramientas no comparten ese bloqueo. No se activa UFW, no se cambian políticas por defecto y no se instala un servicio persistente. El servicio GNS3 depende de la sesión systemd del usuario; los permisos UFW sí persisten tras reiniciar. Un fallo de `up` intenta bloquear los puertos, pero deja ZeroTier encendido para diagnóstico.

## Verificación

```bash
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s tests -p 'test_labctl.py'
```

Resultado de implementación: 15 pruebas aprobadas y tres casos de fallo corregidos y comprobados por revisión independiente. La revisión nativa de Gentle AI no pudo iniciarse (`immutable_review_transport_unsupported`); no hay aprobación nativa ni se cambió su configuración.

Las pruebas simulan comandos y servicios: no cambian la red real. No se ha ejecutado un arranque/apagado real ni una modificación de UFW durante la implementación.

Referencia: [manual oficial de UFW](https://manpages.ubuntu.com/manpages/noble/man8/ufw.8.html), opciones `insert`, `delete` y `comment`.
