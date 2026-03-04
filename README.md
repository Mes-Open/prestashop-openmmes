# OpenMES Connector for PrestaShop

A PrestaShop module that automatically creates **work orders** in [OpenMES](https://github.com/Mes-Open/OpenMes) whenever a customer places an order containing a product marked as "manufactured".

> **Part of the OpenMES ecosystem** — see the main project at [github.com/Mes-Open/OpenMes](https://github.com/Mes-Open/OpenMes)

---

## Features

- Flag any product as *"Manufacture in OpenMES"* directly from the product edit page
- Assign products to specific production lines (or use a global default)
- On order validation, a work order is automatically created in OpenMES via API
- Full order metadata (order reference, product name, customer ID) stored in `extra_data`
- All API calls logged in PrestaShop's message log

---

## Requirements

| Requirement | Version |
|---|---|
| PrestaShop | 1.7+ / 8.x |
| PHP | 7.4+ |
| OpenMES | any version with REST API |
| PHP extension | `curl` |

---

## Installation

1. Go to **Back Office → Modules → Module Manager → Upload a module**
2. Upload `openmesconnector.zip`
3. Click **Configure** once installed

Or manually copy `openmesconnector/` into `<prestashop>/modules/` and install from the module list.

---

## Configuration

1. Go to **Modules → OpenMES Connector → Configure**
2. Fill in:
   - **OpenMES API URL** — e.g. `https://demo.getopenmes.com`
   - **API Token** — generate one in OpenMES: **Settings → API Tokens**
   - **Default production line** — where orders go when no specific line is assigned
3. Enable the integration

---

## Marking products as manufactured

1. Open any product in **Catalog → Products**
2. Go to the **OpenMES** tab
3. Toggle **Manufacture this product** → Yes
4. Optionally select a production line for this product
5. Save

---

## How it works

```
Customer places order
        │
        ▼
hookActionValidateOrder fires
        │
        ▼
For each product → is "manufacture" flag set?
  No  → skip
  Yes → POST /api/v1/work-orders to OpenMES
        {
          "order_no":    "PS-XBKP7DYQ-42",
          "planned_qty": 2,
          "line_id":     3,
          "description": "PrestaShop order #XBKP7DYQ — Widget XL",
          "extra_data":  { "source": "prestashop", ... }
        }
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Work orders not created | Check integration is **Enabled** and token is valid |
| `cURL error` in logs | Verify OpenMES URL is reachable from the PS server |
| `401 Unauthorized` | Token expired — generate a new one in OpenMES |
| `422 Unprocessable` | Duplicate `order_no` — order already exists in OpenMES |

Logs: **Advanced Parameters → Logs** (filter by `[OpenMES]`).

---

## Related

- [OpenMES — main repository](https://github.com/Mes-Open/OpenMes)
- [OpenMES documentation](https://github.com/Mes-Open/OpenMes#readme)

---

## License

MIT — see [LICENSE](LICENSE)
