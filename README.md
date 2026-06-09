# 📋 Plugin: Termos de Responsabilidade — GLPI 11

Plugin para **geração, assinatura eletrônica e gestão de Termos de Responsabilidade de equipamentos** diretamente no GLPI 11.

> Desenvolvido para ambientes corporativos que precisam formalizar a entrega de equipamentos de TI com assinatura digital, auditoria e geração automática de PDF.

---

## ✨ Funcionalidades

| Recurso | Descrição |
|---|---|
| 📄 **Geração de termos** | Cria termos vinculados a computadores e usuários do GLPI |
| ✍️ **Assinatura presencial** | Pad de assinatura digital diretamente no GLPI |
| 🔗 **Assinatura remota** | Link por e-mail para assinatura sem login no GLPI |
| 📦 **Termo de devolução** | PDF de devolução com atualização automática do inventário |
| 📧 **Envio em lote** | Envio para múltiplos usuários com barra de progresso |
| 📂 **Importação CSV** | Importa lotes via arquivo CSV (serial, usuario, email) |
| 🖨️ **PDF com logo** | Geração de PDF com logo da empresa, layout profissional |
| ⚙️ **Configuração total** | Campos, textos, cláusulas e e-mail totalmente configuráveis |
| 🔍 **Auditoria** | Hash SHA-256, IP, User-Agent e data/hora registrados no PDF |

---

## 📋 Requisitos

| Componente | Versão mínima |
|---|---|
| GLPI | 11.x |
| PHP | 8.1+ |
| MySQL / MariaDB | 8.0+ / 10.5+ |
| Extensões PHP | `mbstring`, `gd`, `fileinfo`, `mysqli` |
| TCPDF | Incluído em `lib/tcpdf/` (ou via Composer) |

---

## 🚀 Instalação

### Opção 1 — Script automático (recomendado)

```bash
# Clone o repositório
git clone https://github.com/lucasldantas/termos-responsabilidade-glpi.git

# Entre na pasta
cd termos-responsabilidade-glpi

# Execute o instalador (como root ou com sudo)
chmod +x install.sh
sudo ./install.sh
```

O script irá:
1. Solicitar o caminho do GLPI
2. Solicitar as credenciais do MySQL
3. Copiar os arquivos para `plugins/termosresponsabilidade/`
4. Criar a tabela no banco de dados
5. Instalar a página pública de assinatura em `public/term_sign.php`
6. Ajustar permissões de arquivos

---

### Opção 2 — Instalação manual

#### 1. Copiar os arquivos

```bash
cp -r termos-responsabilidade-glpi /var/www/html/glpi/plugins/termosresponsabilidade
```

#### 2. Instalar a página pública de assinatura

> ⚠️ **Obrigatório para assinatura remota por e-mail.**  
> O arquivo precisa ficar fora do controle do Symfony para ser acessado sem login.

```bash
cp /var/www/html/glpi/plugins/termosresponsabilidade/public_sign_standalone.php \
   /var/www/html/glpi/public/term_sign.php
```

#### 3. Criar a tabela no banco de dados

```bash
mysql -u USUARIO -p BANCO_GLPI < /var/www/html/glpi/plugins/termosresponsabilidade/sql/install.sql
```

#### 4. Criar o diretório de configuração

```bash
mkdir -p /var/www/html/glpi/plugins/termosresponsabilidade/config
```

#### 5. Ajustar permissões

```bash
chown -R www-data:www-data /var/www/html/glpi/plugins/termosresponsabilidade
chmod -R 755 /var/www/html/glpi/plugins/termosresponsabilidade
chmod -R 775 /var/www/html/glpi/plugins/termosresponsabilidade/config

# Diretório onde os PDFs são salvos
mkdir -p /var/www/html/glpi/files/_plugins/termosresponsabilidade
chown -R www-data:www-data /var/www/html/glpi/files/_plugins/termosresponsabilidade
chmod -R 775 /var/www/html/glpi/files/_plugins/termosresponsabilidade
```

#### 6. Ativar no GLPI

1. Acesse o GLPI como **Administrador** ou **Super-Admin**
2. Vá em **Configurações → Plugins**
3. Localize **Termos de Responsabilidade** e clique em **Instalar** e depois **Ativar**

---

## ⚙️ Configuração inicial

Após ativar o plugin, acesse:

**Ferramentas → Termos de Responsabilidade → ⚙️ Configurações**

### Campos do termo (dinâmicos)

Configure quais campos do equipamento e do responsável aparecem nos termos, PDFs e e-mails:

| Campos disponíveis — Equipamento | Campos disponíveis — Responsável |
|---|---|
| Nome do Computador | Nome Completo (Nome + Sobrenome) |
| Número de Série | Nome / Sobrenome separados |
| Patrimônio | Login GLPI |
| Setor/Entidade | Telefone |
| Modelo | Título |
| Fabricante | Número Administrativo |
| | Categoria |

Para cada campo você pode:
- ✅ Ativar ou desativar exibição
- ✏️ Personalizar o rótulo exibido no termo
- ➕ Adicionar campos extras com label customizado
- 🗑️ Remover campos adicionados manualmente

### Logo da empresa

Acesse **Gerenciar Logo** para fazer upload da imagem (PNG, JPG, GIF ou WEBP).
A logo aparece centrada no topo dos PDFs gerados.

### Textos configuráveis

- **Cláusulas** do termo de responsabilidade (uma por linha)
- **Rodapé** do termo de responsabilidade
- **Texto** do termo de devolução
- **Labels** das linhas de assinatura no rodapé (ex: "Emitido por", "Recebido por")

### Template de e-mail

Configure livremente o **assunto** e o **corpo** do e-mail de solicitação de assinatura.
O corpo aceita **HTML completo** — inclua imagens, formatação, cores e logotipos via HTML/CSS.

Use a função **👁️ Visualizar** para ver como o e-mail será renderizado antes de salvar.

#### Variáveis disponíveis no e-mail

| Variável | Descrição |
|---|---|
| `{nome}` | Nome completo do responsável |
| `{computador}` | Nome do computador |
| `{serial}` | Número de série |
| `{setor}` | Setor/Entidade |
| `{link}` | Link de assinatura |
| `{dias_validade}` | Validade do link em dias |

---

## 📖 Como usar

### Criando um único termo

1. **Ferramentas → Termos de Responsabilidade → + Adicionar**
2. Selecione o computador (o usuário vinculado é preenchido automaticamente)
3. Escolha entre:
   - **✍️ Assinar Agora** — abre a tela de assinatura presencial
   - **📧 Enviar por E-mail** — envia link de assinatura remota para o usuário

### Assinatura presencial

A tela exibe todos os campos configurados (equipamento + responsável), as cláusulas do contrato e um pad de assinatura digital. Ao confirmar, o PDF é gerado e salvo automaticamente.

### Assinatura remota

O usuário recebe um e-mail com um link único e com prazo de validade configurável. Ao acessar o link, a página de assinatura é exibida **sem necessidade de login no GLPI**. Após assinar, o PDF é gerado e o termo marcado como **Assinado**.

### Envio em lote

1. Clique em **+ Enviar Lote**
2. Preencha as linhas manualmente **ou** importe via CSV
3. Clique em **📧 Enviar Todos os Links**
4. Acompanhe o progresso em tempo real — os e-mails são enviados um a um com intervalo de **500ms** para evitar flooding no servidor de e-mail

#### Formato do CSV para importação

```csv
serial,usuario,email
SN123456,joao.silva,joao@empresa.com
SN654321,maria.santos,maria@empresa.com
```

| Coluna | Obrigatório | Descrição |
|---|---|---|
| `serial` | ✅ | Número de série exato do computador no GLPI |
| `usuario` | ✅ | Login do usuário no GLPI (campo `name`) |
| `email` | ✅ | E-mail de destino para o link de assinatura |

> **Dica:** Clique em **📥 Baixar template CSV** para obter o arquivo modelo pronto para preenchimento.

### Devolvendo um equipamento

Na lista de termos, clique em **📦 Devolução** no termo assinado.
O sistema irá:
1. Exibir o formulário de devolução com os dados do equipamento
2. Gerar o PDF do Termo de Devolução
3. Permitir atualizar status, localização e usuário do computador no inventário GLPI
4. Marcar o termo como **Revogado**

---

## 🗂️ Estrutura de arquivos

```
termosresponsabilidade/
├── config/                    # Configurações e logo (runtime, não versionado)
│   ├── settings.json          # Configurações salvas pelo admin
│   └── logo.*                 # Logo da empresa
├── css/
│   └── style.css
├── front/
│   ├── devolution.php         # Tela de devolução de equipamento
│   ├── download.php           # Download do PDF assinado
│   ├── logo_serve.php         # Serve a logo via HTTP
│   ├── logo_upload.php        # Upload da logo
│   ├── settings.php           # Configurações do plugin
│   ├── sign.php               # Assinatura presencial (requer login GLPI)
│   ├── term.batch.php         # Envio em lote + importação CSV
│   ├── term.form.php          # Formulário de novo termo
│   └── term.php               # Lista de termos
├── inc/
│   ├── ajax.class.php         # Classe auxiliar de AJAX
│   ├── menu.class.php         # Registro do menu no GLPI
│   ├── pdf.class.php          # Geração de PDFs (TCPDF)
│   └── term.class.php         # Classe principal do plugin
├── lib/
│   ├── signature_pad.min.js   # Biblioteca de assinatura digital
│   └── tcpdf/                 # TCPDF incluído (sem necessidade de Composer)
├── sql/
│   └── install.sql            # Script de criação da tabela
├── public_sign_standalone.php # Página pública — copiar para public/term_sign.php
├── hook.php                   # Hooks de instalação/desinstalação do GLPI
├── setup.php                  # Registro do plugin no GLPI
├── composer.json
├── install.sh                 # Instalador interativo automático
├── .gitignore
└── README.md
```

---

## 🗄️ Estrutura do banco de dados

### Tabela: `glpi_plugin_termos_terms`

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | INT UNSIGNED | Chave primária |
| `computers_id` | INT UNSIGNED | ID do computador (ref. `glpi_computers`) |
| `users_id` | INT UNSIGNED | ID do usuário responsável (ref. `glpi_users`) |
| `entities_id` | INT UNSIGNED | ID da entidade (ref. `glpi_entities`) |
| `issuer_id` | INT UNSIGNED | ID do usuário GLPI que emitiu o termo |
| `status` | ENUM | `pending` / `signed` / `revoked` |
| `signature_data` | LONGTEXT | Assinatura em base64 (PNG do canvas) |
| `pdf_path` | VARCHAR(512) | Caminho do PDF gerado no servidor |
| `pdf_hash` | VARCHAR(64) | SHA-256 do PDF para auditoria |
| `sign_token` | VARCHAR(64) | Token único para assinatura remota |
| `token_expires_at` | DATETIME | Data/hora de expiração do token |
| `date_creation` | DATETIME | Data de criação do termo |
| `date_signed` | DATETIME | Data em que o termo foi assinado |
| `signer_ip` | VARCHAR(45) | Endereço IP do signatário |
| `signer_agent` | VARCHAR(512) | User-Agent do navegador do signatário |

---

## 🔐 Permissões

| Ação | Perfil necessário |
|---|---|
| Ver termos | Usuário autenticado com acesso ao plugin |
| Criar / enviar termos | Usuário autenticado com acesso ao plugin |
| Excluir termos | Admin / Super-Admin |
| Acessar configurações | Admin / Super-Admin (`config: READ`) |
| Assinar via link remoto | Nenhum login (token válido basta) |

---

## 🔧 Solução de problemas

### "⚠️ Link Inválido" ao acessar o link de assinatura

**Causa:** O arquivo `public/term_sign.php` não foi copiado, ou a URL base do GLPI está incorreta.

**Solução:**
```bash
cp /var/www/html/glpi/plugins/termosresponsabilidade/public_sign_standalone.php \
   /var/www/html/glpi/public/term_sign.php
```
Verifique também: **Configuração → Configuração geral → URL base do GLPI**.

---

### E-mail não é enviado

**Causa:** Configuração de SMTP ausente no GLPI.

**Solução:** Acesse **Configuração → Notificações → Configurar acompanhamento de e-mails** e configure o servidor SMTP. Teste com uma notificação de teste do próprio GLPI antes de usar o plugin.

---

### PDF gerado em branco ou com erro TCPDF

**Causa:** Permissão insuficiente no diretório de PDFs, ou extensão `gd` desabilitada.

**Solução:**
```bash
chmod -R 775 /var/www/html/glpi/files/_plugins/termosresponsabilidade
chown -R www-data:www-data /var/www/html/glpi/files/_plugins/termosresponsabilidade
```
Verifique se `extension=gd` está habilitado no `php.ini` e reinicie o PHP-FPM/Apache.

---

### Erro no envio em lote após o primeiro e-mail

**Causa:** O GLPI 11 invalida o token CSRF após cada uso. O plugin já trata isso retornando um novo token em cada resposta AJAX.

**Solução:** Certifique-se de estar usando a versão mais recente do plugin. Limpe cache do navegador se o problema persistir.

---

### Logo não aparece no PDF

**Causa:** Arquivo de logo não enviado, ou extensão `fileinfo` desabilitada.

**Solução:** Acesse **Configurações → Gerenciar Logo** e faça o upload. Verifique se `extension=fileinfo` está ativo no `php.ini`.

---

## 📝 Licença

MIT License — livre para uso, modificação e distribuição.

---

## 👤 Autor

**Lucas Dantas**
- GitHub: [@lucasldantas](https://github.com/lucasldantas)
- Repositório: [termos-responsabilidade-glpi](https://github.com/lucasldantas/termos-responsabilidade-glpi)

---

## 🤝 Contribuindo

1. Fork o repositório
2. Crie sua branch: `git checkout -b feature/minha-feature`
3. Commit: `git commit -m 'feat: minha feature'`
4. Push: `git push origin feature/minha-feature`
5. Abra um Pull Request
