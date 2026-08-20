#!/bin/bash
# Grava as credenciais do banco no .env sem expor a senha em chat ou histórico.
# Uso:  bash ~/cerne/configurar-banco.sh
set -e
read -p Nome do banco:  DB
read -p Usuario do banco:  USR
read -s -p Senha do banco:  PWD; echo

ENVF=~/cerne/.env
sed -i s|^DB_DATABASE=.*|DB_DATABASE=${DB}| $ENVF
sed -i s|^DB_USERNAME=.*|DB_USERNAME=${USR}| $ENVF
sed -i s|^DB_PASSWORD=.*|DB_PASSWORD=${PWD}| $ENVF

echo Testando conexao...
if mysql -h 127.0.0.1 -u $USR -p$PWD $DB -e SELECT 1; >/dev/null 2>&1; then
  echo CONEXAO-OK
else
  echo CONEXAO-FALHOU - confira os dados no hPanel
  exit 1
fi
