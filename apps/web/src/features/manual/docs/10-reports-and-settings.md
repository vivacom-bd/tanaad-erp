# Reports and Settings

Reports gives a site manager plain tables for a chosen period. Settings is where the business keeps its own lists, products, machines and rules, so nothing needs a developer.

Who uses this: site managers, the CEO and group admins.

### Reports (Manage > Reports)
**Who:** site manager, CEO, group admin  **Site:** the site you opened. Shortcut: g m.
**What it is for:** to see output, cash, exceptions and dispatches for a period, and to print or export them.
**How to use it:**
1. Open **Reports**.
2. Choose the period: **Today**, **This week**, **Last 7 working days** or **This month**. Keys 1, 2, 3 and 4 do the same.
3. Read **Daily summary**. It shows output, what was due, kilos in, waste, downtime, people on shift, cash in, cash out and loads out, with a total row.
4. Read **Output by machine**, **Cash by category**, **Exceptions raised** (raised, still open, critical) and **Dispatches** (delivery note, client, expected kg, gate kg, difference, gate result).
5. Press **CSV** on a table to download it. Press **Print** to print the page.
**Good to know:**
- Only working days count. The current day shows "so far".
- Where the server is connected, a costing section appears at the bottom.
- Empty tables say that nothing was recorded in the period.

### Settings: overview (Manage > Settings)
**Who:** site manager, CEO, group admin  **Site:** all; pick one with the **Site** buttons at the top. Shortcut: g ,.
**What it is for:** everything the business defines for itself. A change appears on every screen at once.
**How to use it:**
1. Open **Settings**. Pick a section on the left, or press keys 1 to 8.
2. Sections: **Master data**, **Company details**, **Products and prices**, **Machines and benchmarks**, **Store zones**, **Rules and limits**, **People and roles**, **Sites and process**.
3. The badge at the top shows how many example values still need confirming for the site.
**Good to know:**
- Group admin can change everything. Site manager can change the lists, products, machines, zones and rules of their own site, and is locked to that site. The CEO can look but not change.
- Only a group admin can change the exchange reference and the company details.
- Changes are recorded in the audit log. If the server refuses a change you see "Not saved" with the reason.

### Master data (Settings > Master data)
**Who:** site manager and group admin can edit; CEO can view  **Site:** per site or group-wide
**What it is for:** the option lists used on screens: sizes, units, categories, colours and so on.
**How to use it:**
1. Choose a list from the groups on the left: People (Sectors, Contract terms, Daily rates), Cash (Expense categories, Expense sub-categories, Quick expenses, Cash denominations), Production (Raw materials, Colours, Pellet sources, Bag sizes, Bag colours, Brands, Pellet grades, Waste sources, Downtime reasons), Maintenance (Breakdown causes), Buying (Intake deductions, Intake prices), Selling (Markets, Payment methods, Credit terms), Dispatch (Vehicles) and General (Units of measure).
2. Type a **Name** in the add row. Fill the value or **Colour** if the list has one, or **Belongs to** for sub-lists. Press **Add**.
3. On a row, use **Move up** and **Move down** to reorder, **Edit** to rename (then **Save** or **Cancel**), and **Deactivate** or **Reactivate**.
**Good to know:**
- Nothing is deleted. A deactivated option disappears from screens but stays on old records.
- Site lists belong to one site. Group lists (Expense categories, Cash denominations, Markets, Payment methods, Credit terms, Units of measure) are shared by all sites and only a group admin can change them.
- A name already in the list is refused. Items marked "example, confirm with site" need checking.

### Products and prices (Settings > Products and prices)
**Who:** site manager and group admin edit  **Site:** per site
**What it is for:** the products a site makes and sells.
**How to use it:**
1. Press **New product** (or key N), or click a row to edit.
2. Fill **Factory name** (required) and **Commercial name** (shown on invoices).
3. Choose **Type**, **Sold by** (units come from Master data) and **Pieces per unit**.
4. Enter **Standard weight (kg)**, **Gate tolerance (%)** and **Weight is** (Still to be measured or Measured on a scale).
5. For bags and rolls enter **Film thickness (microns)**.
6. Under **Prices** set Local and Export amounts and currency (UGX or USD).
7. Tick **Active** and press **Save**.
**Good to know:**
- A film thickness under 30 microns shows a warning: carrier bags below it may not be made or sold in Uganda.
- Use **Search products** to find one.

### Machines and benchmarks (Settings > Machines and benchmarks)
**Who:** site manager and group admin edit  **Site:** per site
**What it is for:** the machines at the site and how much each should make.
**How to use it:**
1. Press **New machine** or click a row.
2. Fill **Name or number**, **Kind**, **Process stage**, optional **Rated capacity (kg per day)** and **Status** (Active, Arriving, Down, Retired).
3. Press **Save**.
4. In **Benchmarks**, enter the units per shift for each machine and product.
**Good to know:**
- Benchmarks need at least one machine and one product.

### Other sections
- **Company details:** address, phone, email, TIN, mobile money merchant, bank details and VAT rate. Blank details print "to be provided" on documents. Only a group admin can edit.
- **Store zones:** add or rename the places where stock is kept.
- **Rules and limits:** gate weight tolerance, allowed process loss, cash close variance, benchmark floor, expense approval limit, when to archive casual workers, and the warning before a contract ends. The **Exchange reference** (UGX per 1 USD) applies to later records only and is group admin only.
- **People and roles:** who can open which portal. Accounts, passwords and PINs are not created here.
- **Sites and process:** shifts and process stages. These are set up with Vivacom and are read only.
