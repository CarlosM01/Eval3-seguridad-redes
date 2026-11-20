# External remote client (GNS3 node REMOTO-SUPERVISOR) on ISP-WAN Gi0/3, 192.0.2.10/24 (RFC 5737).
# Profile P-03 (supervisor): OpenVPN client to FW-ZONA-CENTRO (203.0.113.2:1194) with a PKI-CA user
# certificate. Also the external tester for P-06 (NAT 443 to the DMZ) and E-03 (scan seen by Suricata).
# Persistent state (GNS3 extra_volumes): /etc/openvpn/cliente (key, CSR, certificate, profile).
FROM alpine:latest

RUN apk add --no-cache openvpn openssl curl nmap nmap-scripts netcat-openbsd bash bind-tools

CMD ["/bin/sh"]
