# VoiceAI - FreePBX Module

A FreePBX module that automatically connects inbound calls to Voice AI agent platforms via SIP bridging. Enter your provider API key, select an agent, and the module handles all SIP trunk and endpoint configuration automatically.

## Supported Providers

| Provider | SIP Method | Auth Method | Transport | Codecs |
|----------|-----------|-------------|-----------|--------|
| **VAPI** | Static SIP endpoint | Digest (username/password) | UDP | ulaw, alaw |
| **Retell AI** | Per-call AGI registration | Bearer token (API key) | UDP | ulaw, alaw |
| **ElevenLabs** | SIP trunk phone number | IP allowlisting | TCP | G.722, ulaw, alaw |
| **Ultravox** | SIP registration (Ultravox registers to PBX) | SIP registration | UDP | ulaw, alaw |

## Requirements

- **FreePBX 17.0.1** or later
- **Asterisk** with PJSIP (included with FreePBX 17)
- **PHP 7.4+** with curl extension
- An account with at least one supported Voice AI provider
- A valid API key from the provider(s) you want to use

## Installation

### Method 1: Manual Install (Recommended)

1. Download or clone this repository:

```bash
cd /var/www/html/admin/modules
git clone https://github.com/Samcotech/voiceai-freepbx-module.git voiceai
```

2. Set proper ownership:

```bash
chown -R asterisk:asterisk /var/www/html/admin/modules/voiceai
```

3. Install and enable the module via FreePBX CLI:

```bash
fwconsole ma install voiceai
fwconsole reload
```

### Method 2: Upload via FreePBX GUI

1. Download the repository as a ZIP file
2. In FreePBX, go to **Admin > Module Admin**
3. Click **Upload Modules** and upload the ZIP
4. Click **Install** and then **Apply Config**

### What the Installer Does

- Adds `#include pjsip.voiceai.conf` to `/etc/asterisk/pjsip_custom.conf`
- Creates `/etc/asterisk/pjsip.voiceai.conf` (auto-generated PJSIP configuration)
- Deploys AGI scripts (`retell-bridge.php`, `ultravox-bridge.php`) to `/var/lib/asterisk/agi-bin/`
- Creates database tables `voiceai_providers` and `voiceai_agents`

## Configuration

### Step 1: Configure Provider API Keys

1. Navigate to **Applications > Voice AI Agent** in FreePBX
2. Click the **Settings** button (gear icon) in the top right
3. Enter your API key for each provider you want to use
4. Click **Test** to verify the API key works
5. Check **Enabled** for each provider
6. Click **Save Settings**

#### Provider-Specific Settings

**VAPI**
- API Key: Found at [VAPI Dashboard](https://dashboard.vapi.ai) > Organization Settings > API Keys

**Retell AI**
- API Key: Found at [Retell Dashboard](https://www.retellai.com/dashboard) > API Keys

**ElevenLabs**
- API Key: Found at [ElevenLabs](https://elevenlabs.io) > Profile > API Keys
- The API key must have **editor** role on the agents you want to use

**Ultravox**
- API Key: Found in your Ultravox account settings
- **SIP Domain**: Your Ultravox account's SIP domain (found in Ultravox SIP Settings > IP Allowlisting > Domain)
- **PBX SIP Extension**: The extension number Ultravox registers on your PBX (e.g., `105`). Configure this in the Ultravox dashboard under SIP Registration, pointing to your PBX IP

### Step 2: Add an AI Agent

1. Go to **Applications > Voice AI Agent**
2. Click **Add Agent**
3. Select a **Provider** from the dropdown (only providers with configured API keys appear)
4. Click **Fetch Agents** to load your agents from the provider's API
5. Select an **AI Agent** from the list
6. Set a **Display Name** (auto-filled from the provider)
7. Set **Call Timeout** (default: 300 seconds / 5 minutes)
8. Click **Submit**

The module automatically:
- Creates the SIP endpoint/trunk configuration
- Sets up authentication as required by the provider
- Registers the agent with the provider's SIP infrastructure
- Generates the Asterisk dialplan

### Step 3: Route Calls to the Agent

1. Go to **Connectivity > Inbound Routes**
2. Create or edit an inbound route
3. Set the **Destination** to **Voice AI Agent > [Your Agent Name]**
4. Click **Submit** and **Apply Config**

Now inbound calls matching that route will be connected to your Voice AI agent.

## How Each Provider Works

### VAPI

VAPI uses a static SIP endpoint with digest authentication.

**Call Flow:**
1. Inbound call arrives at FreePBX
2. FreePBX dials `PJSIP/{sip_user}@voiceai-{id}`
3. Asterisk sends SIP INVITE to `sip.vapi.ai:5060` with digest auth credentials
4. VAPI authenticates and connects the call to the selected agent

**What the module configures:**
- PJSIP endpoint with `from_domain=sip.vapi.ai`
- Digest auth using a generated SIP username and the API key as password
- AOR contact pointing to `sip.vapi.ai`
- Identify section matching VAPI's server IPs

**Setup Bridge API Call:**
- `POST /phone-number` — Creates a SIP-type phone number with the assistant linked

### Retell AI

Retell AI uses dynamic per-call registration. Each call requires a unique SIP endpoint registered via the Retell API before the call is placed.

**Call Flow:**
1. Inbound call arrives at FreePBX
2. AGI script (`retell-bridge.php`) executes
3. AGI calls Retell API: `POST /v2/register-phone-call` with the agent ID, caller number, and called number
4. Retell returns a unique `call_id`
5. FreePBX dials `PJSIP/{call_id}@voiceai-retell`
6. Asterisk sends SIP INVITE to `sip.retellai.com` with the registered call ID
7. Retell connects the call to the agent

**What the module configures:**
- A shared PJSIP endpoint `voiceai-retell` for all Retell agents
- Digest auth with the API key
- AGI script deployment to `/var/lib/asterisk/agi-bin/`
- Dialplan with AGI call before the Dial command

### ElevenLabs

ElevenLabs uses SIP trunking with IP-based authentication and TCP transport.

**Call Flow:**
1. Inbound call arrives at FreePBX
2. FreePBX dials `PJSIP/voiceai-{id}@voiceai-{id}`
3. Asterisk sends SIP INVITE to `sip.rtc.elevenlabs.io:5060` over TCP
4. ElevenLabs matches the phone number identifier in the SIP URI
5. ElevenLabs routes the call to the agent assigned to that phone number

**What the module configures:**
- PJSIP endpoint with TCP transport and G.722 codec (wideband audio)
- No digest auth (IP allowlisting used instead)
- AOR contact: `sip:voiceai-{id}@sip.rtc.elevenlabs.io:5060;transport=tcp`
- `qualify_frequency=0` (no SIP OPTIONS polling)

**Setup Bridge API Calls:**
- `POST /v1/convai/phone-numbers/create` — Creates a SIP trunk phone number
- `PATCH /v1/convai/phone-numbers/{id}` — Assigns the selected agent to the phone number

**Cleanup on Delete:**
- `DELETE /v1/convai/phone-numbers/{id}` — Removes the phone number from ElevenLabs

**Requirements:**
- API key must have **editor** role on the agent (viewer role cannot assign agents to phone numbers)

### Ultravox

Ultravox uses SIP registration — Ultravox registers as an extension on your PBX, and calls are routed through that registered extension with a pattern that identifies the target agent.

**Call Flow:**
1. Ultravox maintains a SIP registration on your PBX (e.g., extension 105)
2. Inbound call arrives at FreePBX
3. FreePBX dials `PJSIP/voiceai-{id}@{extension}`
4. Asterisk routes the call to Ultravox's registered contact IP
5. Ultravox matches the `toUserPattern` (`voiceai-{id}`) to determine which agent to use
6. Ultravox connects the call to the matched agent

**What the module configures:**
- No static PJSIP endpoint (uses the registered extension)
- Dialplan with `PJSIP/{pattern}@{extension}` format
- `toUserPattern` mapping via Ultravox SIP API

**Setup Bridge API Calls:**
- `GET /api/sip` — Reads current SIP configuration and allowed agents
- `PATCH /api/sip` — Updates `allowedAgents` array with the new `toUserPattern` mapping

**Cleanup on Delete:**
- `PATCH /api/sip` — Removes the agent's `toUserPattern` from `allowedAgents`

**Requirements:**
- Ultravox SIP Registration must be configured in the Ultravox dashboard pointing to your PBX IP
- The PBX SIP Extension (e.g., 105) must be configured in the module settings
- Your PBX IP must be allowlisted in Ultravox SIP settings

## File Structure

```
voiceai/
├── module.xml              # Module metadata, DB schema, dependencies
├── Voiceai.class.php       # Main BMO class (agent CRUD, PJSIP config generation, AJAX handlers)
├── functions.inc.php       # Dialplan generation and FreePBX destination hooks
├── page.voiceai.php        # Page router (form submissions, view routing)
├── install.php             # Post-install setup (PJSIP include, AGI deployment)
├── uninstall.php           # Cleanup on module removal
├── lib/
│   └── ProviderApi.php     # Provider API abstraction (VAPI, Retell, ElevenLabs, Ultravox)
├── agi/
│   ├── retell-bridge.php   # AGI script for Retell per-call SIP registration
│   └── ultravox-bridge.php # AGI script for Ultravox (kept for reference)
└── views/
    ├── grid.php            # Agent list view
    ├── form.php            # Add/Edit agent form with AJAX agent fetching
    └── settings.php        # Provider API key configuration
```

## Database Tables

### `voiceai_providers`

Stores API keys and configuration for each provider.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment ID |
| provider | VARCHAR(50) | Provider identifier (`vapi`, `retell`, `11labs`, `ultravox`) |
| api_key | VARCHAR(500) | Provider API key |
| extra_config | TEXT | JSON — additional config (e.g., Ultravox SIP domain, extension) |
| enabled | BOOLEAN | Whether the provider is enabled |

### `voiceai_agents`

Stores configured Voice AI agents and their SIP bridge details.

| Column | Type | Description |
|--------|------|-------------|
| id | INT (PK) | Auto-increment ID |
| name | VARCHAR(100) | Display name |
| provider | VARCHAR(50) | Provider identifier |
| remote_agent_id | VARCHAR(255) | Agent ID on the provider's platform |
| sip_uri | VARCHAR(500) | SIP domain/host for the bridge |
| sip_user | VARCHAR(255) | SIP username for the bridge |
| transport | VARCHAR(20) | Transport protocol (`udp` or `tcp`) |
| timeout | INT | Call timeout in seconds |
| enabled | BOOLEAN | Whether the agent is enabled |
| config_json | TEXT | JSON — full bridge configuration from provider |

## Troubleshooting

### General

**Agent not appearing as a destination:**
- Ensure the agent is **Enabled**
- Run `fwconsole reload` to regenerate the dialplan

**Call connects but disconnects immediately:**
- Check Asterisk logs: `asterisk -rvvvvv` and look for the relevant SIP INVITE
- Verify the provider's SIP server IP is reachable: `ping sip.vapi.ai`
- Check if the provider endpoint is configured: `asterisk -rx "pjsip show endpoint voiceai-{id}"`

**CHANUNAVAIL (Channel Unavailable):**
- The SIP endpoint can't reach the provider's server
- Check firewall rules — ensure outbound SIP (port 5060) and RTP (ports 10000-20000) are allowed
- For ElevenLabs: ensure TCP port 5060 is open (not just UDP)

### VAPI

**401 Unauthorized:**
- API key may be incorrect — test it in Provider Settings
- The SIP username is auto-generated; re-save the agent to regenerate

### Retell AI

**AGI script errors:**
- Check permissions: `ls -la /var/lib/asterisk/agi-bin/retell-bridge.php` (should be 755, owned by asterisk)
- Check AGI logs: `asterisk -rx "agi set debug on"`
- Verify the API key can register calls: test the "Test Connection" button in settings

### ElevenLabs

**Calls hang up immediately:**
- Verify an agent is assigned to the phone number on ElevenLabs
- Check API key has **editor** role (viewer cannot assign agents)
- Ensure TCP transport is working: `asterisk -rx "pjsip show transport 0.0.0.0-tcp"`

**No audio / one-way audio:**
- ElevenLabs requires RTP symmetric mode (configured automatically)
- Check your firewall allows RTP traffic (UDP 10000-20000)

### Ultravox

**Wrong agent answering:**
- Check the `toUserPattern` mapping: the module uses `voiceai-{id}` as the pattern
- Verify via Ultravox API: `GET /api/sip` should show the correct `allowedAgents` mapping
- Re-save the agent in FreePBX to update the mapping

**404 Not Found:**
- Ensure Ultravox is registered on your PBX: `asterisk -rx "pjsip show contacts"` should show the Ultravox extension
- Verify the SIP Extension in module settings matches the registered extension

## Uninstallation

```bash
fwconsole ma uninstall voiceai
fwconsole ma delete voiceai
fwconsole reload
```

This removes the module, database tables, and dialplan entries. The PJSIP include line in `pjsip_custom.conf` and the AGI scripts in `/var/lib/asterisk/agi-bin/` may need manual cleanup.

## License

GPLv3+ — See [LICENSE](http://www.gnu.org/licenses/gpl-3.0.txt)
