#!/usr/bin/env bash
# =============================================================================
# install.sh — Instalador do Plugin "Termos de Responsabilidade" para GLPI 11
# =============================================================================
set -e

# ── Cores ─────────────────────────────────────────────────────────────────────
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
BLUE='\033[0;34m'; BOLD='\033[1m'; NC='\033[0m'

info()    { echo -e "${BLUE}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[AVISO]${NC} $*"; }
error()   { echo -e "${RED}[ERRO]${NC}  $*"; exit 1; }
ask()     { echo -e "${BOLD}$*${NC}"; }

echo ""
echo -e "${BOLD}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BOLD}║     Instalador — Plugin Termos de Responsabilidade (GLPI)   ║${NC}"
echo -e "${BOLD}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""

# ── 1. Localização do GLPI ────────────────────────────────────────────────────
ask "Diretório raiz do GLPI (ex: /var/www/html/glpi):"
read -rp "> " GLPI_DIR
GLPI_DIR="${GLPI_DIR%/}"

[ -d "$GLPI_DIR" ]             || error "Diretório '$GLPI_DIR' não encontrado."
[ -f "$GLPI_DIR/version" ] || \
[ -f "$GLPI_DIR/inc/config.php" ] || \
[ -f "$GLPI_DIR/src/Application.php" ] || \
    warn "Não foi possível confirmar que '$GLPI_DIR' é um GLPI válido. Continuando mesmo assim..."

PLUGIN_DIR="$GLPI_DIR/plugins/termosresponsabilidade"
PUBLIC_DIR="$GLPI_DIR/public"

# ── 2. Credenciais do MySQL ───────────────────────────────────────────────────
echo ""
info "Configuração do banco de dados MySQL"
ask "Host do MySQL (padrão: localhost):"
read -rp "> " DB_HOST; DB_HOST="${DB_HOST:-localhost}"

ask "Porta do MySQL (padrão: 3306):"
read -rp "> " DB_PORT; DB_PORT="${DB_PORT:-3306}"

ask "Nome do banco de dados do GLPI:"
read -rp "> " DB_NAME
[ -z "$DB_NAME" ] && error "Nome do banco é obrigatório."

ask "Usuário do MySQL:"
read -rp "> " DB_USER
[ -z "$DB_USER" ] && error "Usuário do banco é obrigatório."

ask "Senha do MySQL:"
read -rsp "> " DB_PASS; echo ""

# Testa conexão
info "Testando conexão com o banco de dados..."
if ! mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SELECT 1" &>/dev/null; then
    error "Não foi possível conectar ao banco '$DB_NAME' em $DB_HOST:$DB_PORT. Verifique as credenciais."
fi
success "Conexão com o banco de dados OK."

# ── 3. Usuário web ────────────────────────────────────────────────────────────
echo ""
ask "Usuário do servidor web (padrão: www-data):"
read -rp "> " WEB_USER; WEB_USER="${WEB_USER:-www-data}"

# ── 4. Copia o plugin ─────────────────────────────────────────────────────────
echo ""
info "Instalando arquivos do plugin em '$PLUGIN_DIR'..."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [ -d "$PLUGIN_DIR" ]; then
    warn "Plugin já existe. Fazendo backup em ${PLUGIN_DIR}.bak_$(date +%Y%m%d%H%M%S)..."
    cp -a "$PLUGIN_DIR" "${PLUGIN_DIR}.bak_$(date +%Y%m%d%H%M%S)"
fi

mkdir -p "$PLUGIN_DIR"
# Copia o conteúdo do diretório (não o diretório em si), excluindo .git e install.sh
rsync -a --exclude='.git' --exclude='install.sh' --exclude='*.bak_*' \
    "$SCRIPT_DIR/" "$PLUGIN_DIR/" 2>/dev/null \
|| { cp -a "$SCRIPT_DIR/." "$PLUGIN_DIR/"; rm -rf "$PLUGIN_DIR/.git" "$PLUGIN_DIR/install.sh"; }
success "Arquivos copiados."

# ── 5. Página pública de assinatura ──────────────────────────────────────────
info "Instalando página pública de assinatura em '$PUBLIC_DIR'..."
if [ -d "$PUBLIC_DIR" ]; then
    cp "$PLUGIN_DIR/term_sign.php" "$PUBLIC_DIR/term_sign.php"
    success "Arquivo '$PUBLIC_DIR/term_sign.php' criado."
else
    warn "Diretório '$PUBLIC_DIR' não encontrado. Copie manualmente:"
    warn "  cp $PLUGIN_DIR/term_sign.php $PUBLIC_DIR/term_sign.php"
fi

# ── 6. Cria diretório de config ───────────────────────────────────────────────
mkdir -p "$PLUGIN_DIR/config"
success "Diretório config/ criado."

# ── 7. Banco de dados — cria tabela ──────────────────────────────────────────
info "Criando tabela no banco de dados '$DB_NAME'..."
mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < "$PLUGIN_DIR/sql/install.sql" \
    && success "Tabela 'glpi_plugin_termos_terms' criada (ou já existia)." \
    || error "Falha ao executar SQL. Verifique '$PLUGIN_DIR/sql/install.sql'."

# ── 8. Permissões ─────────────────────────────────────────────────────────────
info "Ajustando permissões..."
chown -R "$WEB_USER":"$WEB_USER" "$PLUGIN_DIR" 2>/dev/null || \
    warn "Não foi possível alterar o dono. Execute manualmente: chown -R $WEB_USER:$WEB_USER $PLUGIN_DIR"

chmod -R 755 "$PLUGIN_DIR"
chmod -R 775 "$PLUGIN_DIR/config"

# Diretório de PDFs gerados pelo GLPI
PDF_DIR="$GLPI_DIR/files/_plugins/termosresponsabilidade"
mkdir -p "$PDF_DIR"
chown -R "$WEB_USER":"$WEB_USER" "$PDF_DIR" 2>/dev/null || true
chmod -R 775 "$PDF_DIR"
success "Permissões ajustadas."

# ── 9. Dependências PHP (TCPDF já incluso em lib/) ───────────────────────────
if command -v composer &>/dev/null; then
    info "Composer encontrado. Instalando dependências em vendor/..."
    cd "$PLUGIN_DIR" && composer install --no-dev --optimize-autoloader 2>/dev/null \
        && success "composer install concluído." \
        || warn "Erro no composer. O plugin usa lib/tcpdf/ como fallback."
    cd - &>/dev/null
else
    info "Composer não encontrado — usando TCPDF incluído em lib/tcpdf/ (sem ação necessária)."
fi

# ── 10. Resumo ────────────────────────────────────────────────────────────────
echo ""
echo -e "${GREEN}${BOLD}╔══════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}${BOLD}║                  Instalação concluída! ✓                    ║${NC}"
echo -e "${GREEN}${BOLD}╚══════════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "  Plugin instalado em:  ${BOLD}$PLUGIN_DIR${NC}"
echo -e "  Página pública em:    ${BOLD}$PUBLIC_DIR/term_sign.php${NC}"
echo -e "  PDFs gerados em:      ${BOLD}$PDF_DIR${NC}"
echo ""
echo -e "${YELLOW}Próximos passos:${NC}"
echo "  1. Acesse o GLPI como administrador"
echo "  2. Vá em Configurações → Plugins → Ative 'Termos de Responsabilidade'"
echo "  3. Acesse Ferramentas → Termos de Responsabilidade → Configurações"
echo "  4. Configure os campos, textos e e-mail conforme necessário"
echo ""
