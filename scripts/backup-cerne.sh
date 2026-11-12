#!/bin/bash
# Backup CIFRADO do Cerne: banco + arquivos privados (PDF de extrato, documentos, fotos). Roda pelo cron da hospedagem (ver DEPLOY.md, seção Backup).
#
# Pré-requisitos no servidor (feitos uma vez, ver DEPLOY.md):
#   ~/.my.cnf        credenciais do banco, chmod 600  (nunca a senha na linha de comando: aparece na lista de processos)
#   ~/.backup-pass   frase que cifra os backups, chmod 600, e uma cópia no cofre de senhas FORA do servidor
#
# Os arquivos saem em ~/backups com extensão .enc; apaga os de mais de DIAS dias. Falha em qualquer etapa sai com erro e NÃO deixa arquivo pela metade.
set -euo pipefail
umask 077

DIAS="${BACKUP_DIAS:-7}"
APP="${HOME}/cerne"
DEST="${HOME}/backups"
PASS="${HOME}/.backup-pass"
DATA="$(date +%F)"

log() { printf '%s %s\n' "$(date '+%F %T')" "$*"; }

[ -f "$PASS" ] || { log "ERRO: falta $PASS"; exit 1; }
[ -f "${HOME}/.my.cnf" ] || { log "ERRO: falta ~/.my.cnf"; exit 1; }
[ -f "$APP/.env" ] || { log "ERRO: falta $APP/.env"; exit 1; }
mkdir -p "$DEST"

BANCO="$(grep -E '^DB_DATABASE=' "$APP/.env" | head -1 | cut -d= -f2- | tr -d "\"'")"
[ -n "$BANCO" ] || { log "ERRO: DB_DATABASE vazio no .env"; exit 1; }

cifrar() { openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:$PASS" "$@"; }

# 1) banco
ALVO_BANCO="$DEST/cerne-banco-$DATA.sql.gz.enc"
mysqldump -h 127.0.0.1 --single-transaction --routines --no-tablespaces "$BANCO" | gzip | cifrar -out "$ALVO_BANCO.parcial"
# confere que decifra e descomprime antes de aceitar o arquivo
cifrar -d -in "$ALVO_BANCO.parcial" | gzip -t
mv "$ALVO_BANCO.parcial" "$ALVO_BANCO"
log "banco ok: $(basename "$ALVO_BANCO") ($(du -h "$ALVO_BANCO" | cut -f1))"

# 2) arquivos privados
ALVO_ARQ="$DEST/cerne-arquivos-$DATA.tar.gz.enc"
tar -C "$APP/storage/app" -czf - private | cifrar -out "$ALVO_ARQ.parcial"
cifrar -d -in "$ALVO_ARQ.parcial" | gzip -t
mv "$ALVO_ARQ.parcial" "$ALVO_ARQ"
log "arquivos ok: $(basename "$ALVO_ARQ") ($(du -h "$ALVO_ARQ" | cut -f1))"

# 3) rotação: só os cifrados desta rotina; sobra de execução interrompida também sai
find "$DEST" -maxdepth 1 \( -name 'cerne-banco-*.enc' -o -name 'cerne-arquivos-*.enc' -o -name '*.parcial' \) -mtime +"$DIAS" -delete
log "rotação ok (guarda ${DIAS} dias)"
