#!/bin/bash
# Shared helpers for the pki-* commands. Sourced, not executed.
set -euo pipefail
umask 077

CNF=/etc/pki/openssl-ca.cnf
MODULE=${PKCS11_MODULE_PATH:-/usr/lib/softhsm/libsofthsm2.so}
PROV=(-provider pkcs11prov -provider default)
ORG="Empresa Logistica"
# openssl-ca.cnf expands $ENV::PKI_SAN when it loads; pki-emitir sets the real value.
export PKI_SAN=${PKI_SAN:-DNS:unset.invalid}
WEB=/var/www/pki

# ca_dir <raiz|emisora>
ca_dir() { echo "/srv/ca/$1"; }

# key_uri <token> [object]: PKCS#11 URI of a private key; the user PIN is
# read from a 0600 file by libp11, so it never appears in argv.
key_uri() { echo "pkcs11:token=$1;object=${2:-$1};type=private?pin-source=file:$(ca_dir "$1")/private/pin"; }

die() { echo "ERROR: $*" >&2; exit 1; }

# init_ca_dir <raiz|emisora>: openssl ca database layout.
init_ca_dir() {
  local d; d=$(ca_dir "$1")
  mkdir -p "$d/private" "$d/emitidos" "$d/crl"
  chmod 0700 "$d/private"
  [ -f "$d/index.txt" ] || : > "$d/index.txt"
  [ -f "$d/serial" ] || openssl rand -hex 16 > "$d/serial"
  [ -f "$d/crlnumber" ] || echo 1000 > "$d/crlnumber"
}

# init_token <raiz|emisora>: SoftHSM2 token with a random PIN and one
# non-extractable RSA-3072 key pair (label and token label = CA name).
init_token() {
  local name=$1 d; d=$(ca_dir "$name")
  if softhsm2-util --show-slots 2>/dev/null | grep -q "Label:.*\b$name\b"; then
    die "token '$name' already exists; refusing to overwrite the CA key"
  fi
  openssl rand -hex 16 > "$d/private/pin"
  openssl rand -hex 16 > "$d/private/so-pin"
  chmod 0600 "$d/private/pin" "$d/private/so-pin"
  softhsm2-util --init-token --free --label "$name" \
    --pin "$(cat "$d/private/pin")" --so-pin "$(cat "$d/private/so-pin")" >/dev/null
  pkcs11-tool --module "$MODULE" --token-label "$name" --login \
    --pin "$(cat "$d/private/pin")" --keypairgen --key-type rsa:3072 \
    --label "$name" --id 01 --sensitive >/dev/null
  # Proof that the key cannot leave the token.
  pkcs11-tool --module "$MODULE" --token-label "$name" --login \
    --pin "$(cat "$d/private/pin")" -O --type privkey 2>/dev/null \
    | grep -q "never extractable" || die "key '$name' is extractable"
  echo "token '$name': RSA-3072 key generated, never extractable"
}
