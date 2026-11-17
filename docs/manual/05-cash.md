# Cash

The cash pages are the daily cash book: money in, money out, a count of the drawer at the end of the day, and a signature. Every shilling out needs a category.
Who uses this: cashiers and site managers write to the cash book. The CEO and group admin can read it.

### Cash book (Cash > Cash book)
**Who:** Cashier, Site manager, CEO, Group admin  **Site:** All sites. Shortcut: g c.
**What it is for:** Shows one day's cash page as a ledger, from opening balance to expected closing, and is where the day is closed.
**How to use it:**
1. Choose the day with the day list, or the previous and next arrows. Press **Today** to come back.
2. Read the four figures at the top: opening balance, money in, money out and expected closing.
3. To add money received, press **Add receipt**, type **Received from**, enter the amount, and press **Record**.
4. For money paid out, press **Record expense**.
5. Click any ledger line to see its full details.
6. Press **CSV** or **Print** to take a copy.
**Good to know:**
- Only the Cashier and the Site manager can add to the book, close it or reopen it. Others see a note saying they can read it.
- Sales, receipts, expenses and wages are shown in separate sections with a running balance.
- When connected to the server, a Cashier or Site manager can set the **Opening balance (UGX)** before the first entry of the day, using **Set opening**.
- **Correct entry** on a line needs a reason. It is not offered on wage payouts or on closed days.

### Closing the day (Cash > Cash book > Count and close day)
**Who:** Cashier, Site manager  **Site:** All sites.
**What it is for:** Counts the drawer, compares it with the expected amount and signs the day off. The sheet is titled "Close the day".
**How to use it:**
1. On an open day, press **Count and close day**.
2. Type how many of each note and coin you have. Press Enter to move down the list.
3. Watch **Counted** and the variance. It shows **Balanced**, short or over, within or beyond tolerance.
4. Sign by drawing in the box or pressing **Sign as** your name.
5. Press **Confirm and sign off**.
**Good to know:**
- The button stays off until you have counted something and signed.
- A variance within tolerance is noted in the audit log. Beyond tolerance, an exception goes to the Site manager.
- The counted amount is carried forward to the next day. The notes and coins listed come from Settings.

### Reopening a signed day (Cash > Cash book > Reopen day)
**Who:** Cashier and Site manager can start it. The Site manager's PIN approves it.  **Site:** All sites.
**What it is for:** Lets a closed day be opened again to add a missing line.
**How to use it:**
1. On a closed day, press **Reopen day**.
2. Type the reason, at least a few words. Press Enter.
3. If asked, choose the **Site Manager** from the list.
4. Hand over to the Site manager, who presses **Enter the PIN** and keys in their own PIN.
5. Add the missing line, then close and sign the day again.
**Good to know:**
- Reopening is written to the audit trail with your reason.
- A wrong PIN is refused. Reopening needs a connection when the site is connected to the server.
- A reopened day shows "Reopened" until it is signed again.

### Record expense (Cash > Record expense)
**Who:** Cashier, Site manager  **Site:** All sites. Shortcut: g e. The page is titled "New expense".
**What it is for:** Records one expense voucher. No category, no voucher.
**How to use it:**
1. Enter the **Amount** (UGX). The **Quick fill** buttons fill the category, the payee and the last amount paid.
2. Choose the **Category**. Number keys pick a category.
3. If asked, choose the kind of expense.
4. For spares, choose the machine under **For which machine?**
5. Enter **Paid to**, or pick a recent payee.
6. Select **Take a photo of the receipt** if you have one.
7. Press **Save expense** (Ctrl+Enter).
**Good to know:**
- Above the approval limit shown on the page, the button reads **Approve and save** and the Site manager keys in a PIN first.
- The receipt photo is optional.
- Other roles see a message that expenses are recorded by the Cashier or the Site manager.
