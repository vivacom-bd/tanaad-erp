# Production

Production is where each shift's work is recorded: machine output, weighing, and stickers on lots. Each site has its own pages, plus four pages that every site shares.
Who uses this: supervisors, site managers, storekeepers, gate staff and cashiers (Recycling intake), with the CEO and group admin viewing.

## All sites

### Machine wall (Production > Machine wall)
**Who:** Supervisor, Site manager, CEO, Group admin, Mechanic  **Site:** All sites. Shortcut: g p.
**What it is for:** Shows every machine of the site for the current shift and lets you log each machine's output.
**How to use it:**
1. Choose **Day shift** or **Night shift** at the top.
2. Click a machine card, or press the **Log next unlogged** button to jump to the next machine with no entry. Use the arrow keys to move between cards. Typing a number on a card starts the count.
3. In the Shift entry panel, pick the **Operator** and the **Product** (or **Grade** at Recycling).
4. Type the numbers shown (good units, rejects, kg, or input and output kg) and any **Downtime** minutes.
5. If asked, choose a **Downtime reason**.
6. Press **Save entry**, or **Save and next** to go straight to the next unlogged machine.
**Good to know:**
- A reason is required when output is below the site's target share of what is due so far, or when downtime is over the limit shown on screen. The entry will not save without it.
- Use **Use same** to copy the operator and product from the machine's last entry.
- The CEO and Group admin see the wall as **Read-only**. The **Table View** tab shows the same machines as a list.

### Shift handover (Production > Shift handover)
**Who:** Supervisor, Site manager, CEO, Group admin  **Site:** All sites. Shortcut: g h.
**What it is for:** The shift book: one row per machine with totals, ready to sign, print or export. The page itself is titled "Shift sheet".
**How to use it:**
1. Pick the day in the day chips and **Day shift** or **Night shift**.
2. Use the search box to find a machine, operator or product.
3. For today's shift, click a row to open its entry and fix or add figures.
4. Press **Print** for the paper copy, which has lines for the supervisor and site manager signatures, or **CSV** to download it.
5. Supervisors and site managers can press **Close shift** when the shift is finished.
**Good to know:**
- Rows marked "Not logged" are still open in the book.
- Past days and the CEO and Group admin views cannot be edited.
- **Close shift** greys out once the shift is closed, and is not available before the shift starts.

### Print labels (Production > Print labels)
**Who:** Supervisor, Site manager, Storekeeper  **Site:** All sites. Shortcut: g j.
**What it is for:** Creates a sticker record for a lot and shows a preview of the sticker. The page is titled "Create label".
**How to use it:**
1. Under **Label kind**, choose the kind of label.
2. Choose the **Product**, and the **Colour** or **Grade** if the page asks for it.
3. Enter the **Pieces** (where shown) and the **Weight** in kg.
4. Choose the **Machine**, and the **Operator** if you wish.
5. Check the preview, then press the **Create ... label** button.
**Good to know:**
- The footer lists what is still missing. The label is not recorded until the list is empty.
- No physical label is printed. The page says so each time.
- Press the small preview button in "Recently recorded" to see an older sticker again.

### Scan label (Production > Scan label)
**Who:** Everyone. Shortcut: g f.
**What it is for:** Looks up any Tanaad sticker and shows its whole life. The page is titled "Scan anything".
**How to use it:**
1. Scan the sticker, or type the code into the box.
2. Press **Look up**.
3. Read the sticker details, what it was made from, what it went into, and the event trail.
4. Click a code under "Made from" or "Went into" to follow the chain.
**Good to know:**
- "Unknown code" means the code is not a Tanaad label on any site. Check the sticker is not damaged.
- With no scanner, the **Simulate a scan** buttons (F2, F4, F8, F9) pick a sticker for you.
- Recent scans are kept in the side list for this session only.

## Mats site

### Mixer (Production > Mixer)
**Who:** Supervisor, Site manager  **Site:** Mats only.
**What it is for:** Records each mixer batch, weighs it and prints its sticker. The page is titled "Mixer batch".
**How to use it:**
1. Choose the **Pellet source**.
2. Under **Bags**, enter the number of bags. The batch total is worked out for you.
3. Choose the **Colour**. Enter the **Lot number** if you have one.
4. Choose the **Mixer operator**.
5. Press **Weigh and label**.
**Good to know:**
- The page shows "Same as last batch" or "Differs from last batch" so you can spot a wrong count.
- Bag weights, pellet sources and colours come from Settings.
- Click a batch in "Today's batches" to see its sticker, and use **Reprint** if needed.

### Extruders (Production > Extruders)
**Who:** Supervisor, Site manager  **Site:** Mats only.
**What it is for:** Logs the string output of each extruder and prints a rack label for the drying racks.
**How to use it:**
1. Press **Log string output**, or click an extruder.
2. Choose the **Extruder** and the **Operator**.
3. Choose the **Input batch** from today's mixer batches (optional).
4. Enter the **String output** in kg.
5. Choose **Print a rack label** or **Log the kilos only**. For a rack label, check the **Rack colour**.
6. Press the **Log ... kg** button.
**Good to know:**
- A rack label makes the string count as work in progress until a loom takes it.
- In the "Drying racks" box, press **Consume rack** when a loom operator takes a rack. The oldest rack of that colour is used.
- Choosing a batch marks it as used by the extruders.

### Finishing (Production > Finishing)
**Who:** Supervisor, Site manager  **Site:** Mats only.
**What it is for:** Turns counted sewn mats into bundles and prints one sticker per bundle. The page is titled "Finishing and bundling".
**How to use it:**
1. Choose the **Product**, the loom under **From loom**, and the **Finisher**.
2. Enter **Pieces sewn**. The page shows full bundles and loose pieces.
3. Choose the **Store zone**.
4. Press **Bundle and print ... sticker(s)**.
**Good to know:**
- You need at least one full bundle to save. Loose pieces stay on the table and are counted on this device until they make a full bundle.
- Use the **Calibration** box to weigh a finished bundle and compare it with the standard weight.
- Some standard weights are marked "To be measured".

### Waste (Production > Waste)
**Who:** Supervisor, Site manager  **Site:** Mats only.
**What it is for:** Weighs trim and faulty string in, and weighs crusher output out, so waste is a measured number. The page is titled "Waste loop".
**How to use it:**
1. In **1 · Weigh trim in**, choose **What is in the sack**.
2. Put the sack on the scale. Read the scale and use the reading. The sticker prints.
3. In **2 · Weigh crusher output**, choose **Where the pellet goes**.
4. Weigh the sack and use the reading. The sticker prints.
**Good to know:**
- Press T to work on trim, C to work on crusher output.
- The top figures compare today's waste share with earlier days.
- If the site is connected to the server, a **Weigh waste** box also appears. Choose what was weighed, enter **Kilos**, optionally a machine and reason, then press **Record weighing**.

## Bags site

### Raw material issue (Production > Raw material issue)
**Who:** Supervisor, Site manager  **Site:** Bags only.
**What it is for:** Records resin and masterbatch bags issued to each extruder for the shift.
**How to use it:**
1. Choose the **Extruder** (a machine marked down cannot be chosen).
2. Choose the **Material**.
3. Enter the number of **Bags**. The kilos are worked out for you.
4. Press **Issue**.
**Good to know:**
- You count bags only. Kilos come from the pack weight set in Settings.
- If a material has no pack weight, the page asks you to set it in Settings.
- "Recent issues" lists issues made on this device.

### Roll stickers (Production > Roll stickers)
**Who:** Supervisor, Site manager  **Site:** Bags only.
**What it is for:** Prints a roll sticker with the same six fields as the paper one, plus a code the next stage can scan. The page is titled "Roll sticker".
**How to use it:**
1. Choose the **Machine**.
2. Choose the **Operator**.
3. Choose the **Roll type**.
4. For **Weight**, put the roll on the scale (**Roll scale**) or choose **Type it** and enter the kg.
5. Press **Print sticker**.
**Good to know:**
- The code is assigned when the sticker prints.
- Printed rolls wait in the roll store until Bag conversion uses them.
- Roll types are set up in Settings under Products.

### Bag conversion (Production > Bag conversion)
**Who:** Supervisor, Site manager  **Site:** Bags only.
**What it is for:** Records rolls cut, sealed and packed into bags, and the loss between kilos in and kilos out. The page is titled "Cutting, sealing and packing".
**How to use it:**
1. Under **Rolls consumed**, scan or type a roll code and press Enter, press **Scan next**, or tick rolls in the list.
2. Choose the **Converter**. Narrow the list with **Size** and **Colour**, then choose the **Product** and **Brand**.
3. Enter **Packs** and **Rejected bags**.
4. Check the **Yield** box.
5. Press **Record and print ... pack label(s)**.
**Good to know:**
- Scanned rolls are marked as used when you record.
- The yield badge says "Within" or "Above" the site's loss threshold.
- Only the first 12 pack labels are shown on screen.

### Film scrap (Production > Film scrap)
**Who:** Supervisor, Site manager  **Site:** Bags only.
**What it is for:** Weighs a sack of film scrap and prints its sticker. The page is titled "Film scrap sacks".
**How to use it:**
1. Choose the **Scrap type**.
2. Choose the **Destination**: re-pelletise on site, or send to the recycling plant.
3. Choose the store zone if asked.
4. Put the sack on the scale (**Scrap scale**).
5. Press **Label sack**.
**Good to know:**
- A sack sent to the recycling plant is recorded as a transfer and keeps the same sticker. The plant receives it by scanning that code.
- Scrap types come from Settings.

## Recycling site

### Scrap weigh-in (Production > Scrap weigh-in)
**Who:** Supervisor, Site manager, Gate, Storekeeper, Cashier  **Site:** Recycling only.
**What it is for:** Weighs a collector's delivery and issues a voucher. The page is titled "Collector intake".
**How to use it:**
1. Choose the **Collector**, or pick **New collector...** and type the name.
2. Read the **Gross weight** on the scale.
3. Enter the **Tare**, choose the **Deduction**, and choose the **Price per kg**.
4. Select **Take scale photo**. It is mandatory.
5. Check the voucher arithmetic, then press **Weigh in and issue voucher**.
6. Press **Mark paid** when the collector is paid.
**Good to know:**
- The gross weight must be above the tare.
- The page warns that the deduction rule is still to be confirmed with the plant.
- Press **Prepare share preview** for a WhatsApp preview. No message is sent.

### Collector ledger (Production > Collector ledger)
**Who:** Supervisor, Site manager, Cashier, CEO  **Site:** Recycling only.
**What it is for:** Shows each collector's deliveries, deductions, payments and what is still owed.
**How to use it:**
1. Search for a collector, or use the **All**, **Unpaid** and **Paid up** tabs.
2. Click a collector to open their deliveries.
3. Press **Pay** on an unpaid voucher.
4. Press **Export CSV**, or **Export this collector**, to download.
**Good to know:**
- It covers the last 10 working days.
- A payment is posted to today's cash book.
- If a voucher is still waiting to sync, wait until the server confirms it before paying.

### Pellet sacks (Production > Pellet sacks)
**Who:** Supervisor, Site manager  **Site:** Recycling only.
**What it is for:** Labels sacks of pellets and shows output per line.
**How to use it:**
1. Choose the **Line**.
2. Choose the **Grade**, then the **Product**.
3. Enter the **Number of sacks** and the **Kg per sack**.
4. Press **Bag and label**.
**Good to know:**
- Kg per sack starts from the product's standard and can be changed.
- Some sack sizes are marked as a working assumption to be confirmed.
- Only the first 10 stickers are shown while printing.
