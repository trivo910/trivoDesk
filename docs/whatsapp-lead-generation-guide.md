# WhatsApp Lead Generation Guide (WaDesk)

A short, practical guide: connect a WhatsApp number, then run a campaign to generate leads.

## 1. Connect a WhatsApp Number

Go to **Devices** → **Add Device**. Pick one of three connection types:

| Method | Best for | How it works |
|---|---|---|
| **Unofficial API (QR / Pairing Code)** | Quick start, personal/small business numbers | Scan a QR code with WhatsApp (Linked Devices), or enter a numeric pairing code instead. No Meta approval needed. |
| **WABA (Meta Cloud API)** | Official business use, higher sending limits, template messages | Connect via **Embedded Signup** (one-click Meta OAuth) or **Manual** (paste Phone Number ID, Access Token, WABA ID from Meta Business Manager). |
| **Twilio** | Teams already using Twilio for WhatsApp | Enter your Twilio WhatsApp credentials. |

Steps for QR/Pairing (fastest way to get started):
1. Devices → Add Device → choose **Unofficial API**.
2. Click **Show QR Code**, then on your phone: WhatsApp → Settings → Linked Devices → Link a Device → scan.
   - Or click **Use Pairing Code** and type the code into WhatsApp instead of scanning.
3. Wait for status to change to **Connected** (auto-refreshes; QR expires after ~90 seconds, just refresh if needed).

Steps for WABA (recommended once you're scaling campaigns):
1. Devices → Add Device → **WABA (Meta Cloud API)** → **Connect with Facebook** (Embedded Signup), or choose **Manual Setup** and paste your Phone Number ID / Access Token / WABA ID from Meta Business Manager.
2. WaDesk verifies the credentials, subscribes your webhook automatically, and shows a health panel (message quality rating, messaging limit tier, webhook status).

Notes:
- Each connected number counts against your plan's device limit.
- New numbers are "warmed up" gradually (sending volume ramps up over the first several days) to avoid bans — this happens automatically, no action needed.
- WABA numbers can send **Template** messages (required for the first message to a new contact); Unofficial API numbers send freeform chat-style messages.

## 2. Build Your Lead List

Leads live in WaDesk as **Contacts**, organized into **Groups**:
- Import contacts via CSV, or capture them through connected forms/flows.
- Group leads (e.g., "Website Signups", "Trade Show 2026") so campaigns can target a specific group.
- Once a lead responds or is qualified, track them through the **Deals** pipeline (Sales Pipeline) attached to their contact record — this is where you move a lead from "New" to "Won/Lost".

## 3. Create a Message Template (for WABA)

If sending via WABA, first create a **Template** (Devices/Templates → New Template):
- Choose type: Standard, Media (image/video/doc header), or Carousel.
- Add header, body text with variables (e.g. `{{1}}` name), footer, and buttons (Call to Action, Quick Reply).
- Submit for Meta approval — status shows as Pending → Approved/Rejected, with a quality score (Green/Yellow/Red) once live.

Unofficial API and Twilio sends can use a custom message directly in the campaign (text, image/video/document, buttons) without Meta approval.

## 4. Launch a Campaign

Go to **Campaigns** → **New Campaign**:
1. **Type**: Text, Template, Button, Media, Flow, or Custom.
2. **Audience**: select one or more Contact Groups (or all contacts) to target.
3. **Content**: pick your approved Template, or write a custom message with header/footer/buttons/quick replies.
4. **A/B Testing (optional)**: enable a split test between two templates/flows/messages to see which converts better.
5. **Smart Delivery (anti-ban) settings**:
   - Throttle: random delay range between messages.
   - Batch size + pause between batches.
   - Daily send limit and a send-time window (e.g., only 9am–7pm).
   These protect your number's health — leave defaults if unsure.
6. **Schedule**: send now, schedule for later, or set it to **recurring** (daily/weekly/monthly) until a chosen end date.
7. Click **Launch**.

## 5. Track Results

The campaign dashboard reports, per campaign: **Sent, Delivered, Read, Failed, Responded, Clicked** counts. Use "Responded" to identify hot leads, then move them into the **Deals** pipeline for follow-up.

## Quick Checklist

- [ ] Connect a number (QR for quick start, WABA for scale)
- [ ] Import/tag contacts into a Group
- [ ] Create & get a Template approved (if using WABA)
- [ ] Launch a campaign targeting that Group
- [ ] Monitor Delivered/Read/Responded and follow up via Deals
