# 🚀 EscrowPay — 3-Party Escrow Marketplace & Delivery Ecosystem
### *Bandhigga Guud ee Nidaamka Qaran ee Lacag-Hayaanka & Ganacsiga Tooska ah (Complete Presentation)*

---

## 📌 1. Guudmar & Ujeeddada Mashruuca (Executive Summary)

**EscrowPay** waa madal dhameystiran oo xal waara u ah aamin-darrada iyo qiyaanada ka dhex dhici jirtay ganacsiga online-ka ah ee Soomaaliya iyo caalamka intiisa kale. Waxay isku xirtaa 4 dhinac oo wada shaqeynaya: **Iibsade (Buyer)**, **Iibiye (Seller/Reseller)**, **Wakiilka Gaadiidka (Delivery Agent)**, iyo **SuperAdmin**.

```mermaid
flowchart TD
    Buyer["🛒 Buyer (Iibsade)"]
    Seller["🏪 Seller / Reseller"]
    Delivery["🛵 Delivery Agent"]
    Admin["🛡️ SuperAdmin (Escrow Vault)"]

    Buyer -->|1. Bixinta Lacagta (Item + $1.50 Delivery)| Admin
    Seller -->|2. Diyaarinta & Xilsaarka Delivery| Delivery
    Admin -->|3. Ansixinta Dispatch-ka| Delivery
    Delivery -->|4. Pickup Seller & Dropoff Buyer| Buyer
    Buyer -->|5. Hubinta & Ansixinta U Dambaysa| Admin
    Admin -->|6. Sii-deynta Lacagta Seller & $1.50 Delivery| Seller
    Admin -->|6. Bixinta $1.50 Delivery Fee| Delivery
```

---

## 👥 2. Afarta Qof ee Nidaamka Ka Shaqeeya (The 4 Roles)

### 🛒 1. Buyer (Iibsade)
- **Marketplace Browsing**: Wuxuu ka dhex raadinayaa badeecooyin (Products) iyo adeegyo (Services) leh video demos iyo sawirro cad.
- **Single Combined Escrow Payment**: Wuxuu hal mar bixinayaa qiimaha alaabta + **$1.50 Delivery Fee** isagoo isticmaalaya EVC Plus, Zaad, Waafi, ama Kaarka Bangiga.
- **Direct Messaging**: Wuxuu toos ula hadli karaa Seller-ka iyo Delivery Agent-ka.
- **Final Approval**: **Buyer-ka kaliya** ayaa awood u leh inuu dhaho *"Waan helay alaabta"* si lacagta loogu wareejiyo Seller-ka iyo Delivery-ga.

### 🏪 2. Seller / Reseller (Iibiye)
- **Product Management**: Ku darista alaabta, maareynta qiimaha, iyo video demo links.
- **Order Acceptance & Dispatch**: Marka order yimaado, wuxuu leeyahay 3 doorasho:
  1. *Assign Specific Delivery Driver* (U xilsaarid darawal gaar ah).
  2. *Open Delivery Pool* (U furid dhammaan darawallada si ay u codsadaan).
  3. *Self / Direct Fulfillment* (Isku geyn toos ah).
- **Wallet & Payouts**: Helitaanka dakhliga saafiga ah (Net Payout) marka Buyer-ku aqbalo, iyo codsashada lacag-bixinta (Withdrawals).

### 🛵 3. Delivery Agent (Gudbiye)
- **Assigned Jobs Hub**: Wuxuu helayaa ogeysiis toos ah marka loo xilsaaro shaqo delivery, wuxuuna leeyahay **Accept** ama **Decline**.
- **Available Open Jobs Pool**: Wuxuu ka dhex dalban karaa (**Apply**) shaqooyinka furan ee suuqa.
- **Live Logistics Flow**:
  - Step 1: *Mark Picked Up from Seller*
  - Step 2: *Mark Delivered to Buyer*
- **Instant Earnings**: Wuxuu helayaa **$1.50** toos loogu shubayo wallet-kiisa marka Buyer-ku xaqiijiyo helitaanka.

### 🛡️ 4. SuperAdmin (Madaxa Nidaamka & Escrow Vault)
- **Escrow Vault Keeper**: Wuxuu gacanta ku hayaa dhammaan lacagaha xiran ilaa inta howshu si nabad ah ku dhamaaneyso.
- **Deliveries Hub & Dispatch**: Ansixinta codsiyada delivery-yada iyo hubinta socodka gaadiidka.
- **Dispute Resolution (Garsoor)**: Xallinta cabashooyinka haddii alaabtu xumaato ama la isku khilaafo.
- **Platform Monitoring**: La socodka dhammaan wadahadallada users-ka (Live Chat Audit) si looga hortago wax is-daba-marin.
- **Withdrawal Approvals**: Ansixinta lacag-bixinta mobile money-ga iyadoo laga goynayo komishanka rasmiga ah.

---

## 🔄 3. Wareegga Shaqada ee Talaabo Talaabo ah (Step-by-Step Flow)

| Talaabada | Qofka Qabanaya | Ficilka (Action) | Xaaladda Nidaamka (Status) |
|---|---|---|---|
| **1** | **Buyer** | Iibsashada alaabta (**Item + $1.50 Delivery**) | `funded` (Locked in Vault) |
| **2** | **Seller** | Aqbalida order-ka & Xilsaarka Delivery | `accepted` / `pending_admin` |
| **3** | **SuperAdmin** | Ansixinta Dispatch-ka Delivery-ga | `assigned` |
| **4** | **Delivery Agent** | Aqbalida Shaqada (**Accept Job**) | `assigned` (Driver Accepted) |
| **5** | **Delivery Agent** | Ka soo qaadida alaabta Seller-ka | `picked_up` |
| **6** | **Delivery Agent** | Gaarsiinta alaabta ee Buyer-ka | `delivered` |
| **7** | **Buyer** | **Xaqiijinta & Sii-deynta Lacagta (Confirm & Release)** | `released` (Paid) |

---

## 💰 4. Qaab-Dhismeedka Dhaqaalaha & Komishanka (Financial Model)

Nidaamku wuxuu abuuraa dakhli joogto ah oo hufan:

```
┌────────────────────────────────────────────────────────────────────────┐
│                      Buyer Pays: $100 Item + $1.50 Delivery            │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
       ┌────────────────────────────┼────────────────────────────┐
       ▼                            ▼                            ▼
┌──────────────┐             ┌──────────────┐             ┌──────────────┐
│    Seller    │             │   Delivery   │             │  SuperAdmin  │
│  Net Payout  │             │  Gross Fee   │             │ Escrow Comm. │
│    $90.00    │             │    $1.50     │             │    $10.00    │
│  (10% Escrow)│             │ (Full Fee)   │             │ (10% Item)   │
└──────┬───────┘             └──────┬───────┘             └──────┬───────┘
       │                            │                            │
       ▼ (On Withdrawal)            ▼ (On Withdrawal)            ▼ (Withdrawal Comm)
Seller receives: $81.00       Delivery receives: $1.497     Admin gets: +$9.00 / +$0.01
(10% Withdrawal Comm)         (0.2% Withdrawal Comm)
```

1. **Escrow Platform Fee (10%)**: Waxaa laga jaraa Seller-ka marka lacagta loo sii daayo.
2. **Standard Delivery Fee ($1.50)**: Buyer-ka ayaa bixiya, Delivery Agent-kuna wuxuu helayaa **$1.50** buuxda.
3. **Delivery Withdrawal Commission (0.2%)**: Marka Delivery Agent-ku lacagta la baxayo (EVC/Zaad/Waafi) waxaa laga jarayaa **0.2%** (Ugu yaraan $0.01).
4. **Seller Withdrawal Commission (10%)**: Marka Seller-ku lacagta la baxayo waxaa laga jarayaa **10%**.

---

## 🌟 5. Astaamaha Gaarka ah ee Casriga ah (Key Innovations)

- **1. Wadahadal Toos ah & Kormeerkooda (Direct Messaging & SuperAdmin Monitor):**
  - Users-ku waxay si toos ah uga wada hadli karaan gudaha nidaamka.
  - SuperAdmin-ku wuxuu leeyahay **Platform Chat Monitor** oo uu ku akhrin karo dhammaan wada-hadallada Buyer ↔ Seller ↔ Delivery si looga hortago qiyaano ama lagu xalliyo khilaafaadka.

- **2. Xoriyadda Buyer-ka (Buyer-Centric Security):**
  - SuperAdmin ama Seller ma qaadan karaan lacagta ilaa **Buyer-ku gacantiisa ku ansixiyo** inuu helay alaabtii saxda ahayd.

- **3. Isku-xirka Mobile Money-ga Soomaalida:**
  - Taageero buuxda oo loogu talagalay **EVC Plus, Waafi, Zaad, iyo Kaadhadhka Bangiyada**, iyadoo si otomaatig ah loogu xisaabinayo lacag-bixinnada.

---

## 🎬 6. Tilmaamaha Bandhigga Tooska ah (Live Demo Script)

Haddii aad qof u bandhigayso nidaamka, u mar talaabooyinkan:
1. **Gal Buyer Dashboard:** Ka iibso badeeco Marketplace-ka adoo isticmaalaya *Buy with Escrow*. Tus in lacagtu tahay **Price + $1.50 Delivery**.
2. **Gal Seller Dashboard:** Fur *Orders*, guji **Accept & Ship**, u xilsaar Delivery Agent ama ku dar *Open Pool*.
3. **Gal SuperAdmin Deliveries Hub:** Tus sida SuperAdmin-ku u ansixinayo dispatch-ka (**Approve Dispatch**).
4. **Gal Delivery Portal:** Tus sida uu Delivery-gu u leeyahay **Accept Job**, kadibna u calaamadeynayo **Mark Picked Up** iyo **Mark Delivered**.
5. **Ku noqo Buyer-ka:** Tus sida uu Buyer-ka ugu soo baxayo banner-ka cagaaran ee **Confirm Receipt & Release Payment**.
6. **Gal Wallets-ka:** Tus sida Seller-ka iyo Delivery Agent-ka loogu shubay lacagtooda, iyo sida SuperAdmin-ku u helay komishankiisii!
