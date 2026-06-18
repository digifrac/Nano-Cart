# Nano Cart User Guide

A plain language guide to running a Nano Cart shop. This walks you through everything you do day to day: signing in, building your catalogue, adding products and images, connecting checkout links, and publishing. It assumes the shop is already installed on your server. If it is not installed yet, that is a separate job covered in INSTALL.md.

This guide is written for the person who runs the shop, not the person who builds it. You do not need to know any code.

> An HTML version of this guide, ready to host or hand to clients, is in GUIDE.html.

---

## How a Nano Cart shop is laid out

A Nano Cart shop has three simple ideas behind it. Understanding them makes everything else easy.

**Categories hold products.** Every product belongs to one category. A potter might have categories like Mugs, Bowls, and Vases. The shop homepage shows your categories. Clicking a category shows the products inside it.

**Each product links to a payment page.** Nano Cart does not take payment itself. It shows your products beautifully, and the Buy button sends the customer to a checkout page you already control: a Stripe payment link, a PayPal button, Gumroad, Square, Ko-fi, or any other hosted checkout. You paste that link into the product, and Nano Cart does the rest.

**The admin is removable.** You make changes through a private admin area. When you finish, the admin can be taken off the server entirely, so there is nothing to break into while you are not using it. Putting it back is how you make your next round of edits. Your developer usually handles uploading and removing the admin.

---

## Signing in

Your admin lives at your shop address followed by `/admin`. For example, if your shop is at `example.com/shop`, your admin is at `example.com/shop/admin`.

1. Open that address in your browser.
2. Enter the password you set during setup.
3. You land on the Dashboard.

A few things worth knowing:

* The admin only works over a secure `https` connection. This is deliberate and protects your password.
* If the admin address shows a "not configured" page or simply does not load, the admin folder is probably not on the server right now. Ask your developer to upload it so you can make changes.
* You can change your password at any time under Settings. See [Changing your password](#changing-your-password).

---

## A tour of the admin

Down the side of the admin you will find these areas:

| Area | What it is for |
|------|----------------|
| **Dashboard** | Your starting point. Shows how many products are live, how many are still drafts, and quick links. |
| **Products** | The list of everything you sell. Add, edit, reorder, and delete products here. |
| **Categories** | The groups your products sit in. Create these before you add products. |
| **Media** | Every image you have uploaded, in one place. |
| **Settings** | Shop wide options: name, currency, layout, checkout style, and your password. |
| **Licence** | Where you paste a licence key to remove the small footer credit. Optional. |
| **Help** | A short reference card built into the admin. |

The rest of this guide goes through these in the order you will actually use them.

---

## Step one: create your categories

Always build at least one category before you add a product, because every product has to be filed under a category.

1. Go to **Categories** and choose to add a new one.
2. Fill in the fields:

| Field | What to put |
|-------|-------------|
| **Name** | The display name customers see, for example `Pottery`. |
| **Slug** | The short web friendly version, for example `pottery`. Lowercase letters, numbers, and nothing fancy. This becomes part of the web address, so keep it tidy. |
| **Description** | A sentence or two shown at the top of the category page. Optional but good for search engines. |
| **Image** | A picture that represents the category on the homepage. Optional. |
| **Sort order** | A number that decides where this category sits among the others. Lower numbers come first. |

3. Save. The category now appears on your shop homepage, ready to hold products.

Repeat for each group of products you sell. Most small shops have somewhere between two and eight categories.

### Featuring categories on the homepage

If you build up a lot of categories, you do not want the homepage to become an endless wall of them. So the homepage shows a fixed number of category cards in **slots**, and you choose which categories fill them.

* The homepage has **6 slots** (when categories show 3 per row) or **8 slots** (4 per row). The number follows your "Categories per row" setting.
* On the **Categories** page there is a **Homepage slots** panel: one dropdown per slot. Pick a category for Slot 1, Slot 2, and so on, in the order you want them to appear. Leave a slot on "Empty" to leave it blank.
* Categories you do not put in a slot are still there for shoppers, in the slide out **Categories** menu (the button at the top of every page). Nothing is lost, it is just not on the homepage.
* To feature a different category, change a slot's dropdown to it and save. To reorder, change which category sits in which slot.

If you leave every slot empty, the homepage simply shows all your categories, the same as before. So you only need to touch this once a shop grows past a homepage full of categories.

---

## Step two: get your checkout links ready

Before you add a product, you need the web address customers go to in order to pay for it. This comes from your payment provider, not from Nano Cart. You create one checkout link per product.

The most common choice is a **Stripe payment link**:

1. Sign in to your Stripe dashboard.
2. Create a payment link for the item, set its price, and copy the link it gives you. It will look something like `https://buy.stripe.com/...`.
3. That copied address is what you paste into the product later.

The same idea applies to **PayPal**, **Square**, **Gumroad**, **Ko-fi**, or any other provider that gives you a shareable checkout or buy page. As long as you can copy a web address that starts with `https://` and leads to a page where the customer pays, Nano Cart can use it.

A tip: keep a simple list of your products and their checkout links somewhere handy while you work. It makes adding products much faster.

---

## Step three: add a product

This is the part you will do most often.

1. Go to **Products** and choose to add a new one.
2. Fill in the fields below.

### The product fields

| Field | Required | What to put |
|-------|----------|-------------|
| **SKU** | Yes | A short code that names the product, in lowercase letters and numbers, for example `mug001`. This is permanent. It becomes the filename and the web address, so it cannot be changed later. Pick something sensible and move on. |
| **Title** | Yes | The product name customers read, for example `Hand thrown stoneware mug`. |
| **Short description** | Yes | One or two sentences, up to 300 characters. Shown on product cards and used by search engines as the page summary. |
| **Long description** | Yes | The full description shown on the product page. You can use simple formatting (headings, bold, bullet lists) and it renders cleanly. |
| **Category** | Yes | Choose one of the categories you created earlier. |
| **Price display** | Yes | Free text, shown exactly as you type it, for example `£32.00` or `From £49`. This is just what the customer reads. The actual charge happens on the checkout page. |
| **Checkout URL** | Yes | The payment link you prepared in step two. Must start with `https://`. |
| **Featured** | No | Promotes the product on listing pages. |
| **Hero featured** | No | Promotes the product in the large banner at the top of the homepage. |
| **Sort order** | No | A number deciding where the product sits within its category. Lower comes first. Leave it blank to sort by newest. |
| **Status** | Yes | `Draft` keeps it hidden while you work. `Published` makes it live on the shop. |

3. Add images (covered next).
4. Save.

A good habit: add the product as a **Draft** first, get the words and pictures right, preview it, then switch it to **Published** when you are happy.

---

## Step four: add product images

Good photos sell. Nano Cart makes them easy and forgiving.

### Uploading

On the product editor you upload one or more images for that product. Allowed types are JPG, PNG, GIF, and WebP. Every upload is processed on the server, so you do not need to worry about file size or compression beforehand.

**Upload large, and the shop will shrink it for you.** A generous source picture, say 1200 by 1200 or bigger, gives the best results. The shop never stretches a small image up, so avoid tiny files.

### Choosing the main image

One image is the **primary** image. That is the one shown first on the product card and at the top of the product page. The others appear in the gallery. You choose which one is primary.

### Fitting the picture

Each image carries its own display settings, so one product never forces an awkward crop on another. For each image you can set:

| Setting | What it does |
|---------|--------------|
| **Width and Height** | The size of the display box the picture sits in. |
| **Fit: Cover** | Fills the whole box and trims any overflow. Best for photographs where losing a little around the edges is fine. |
| **Fit: Contain** | Shows the entire picture with nothing trimmed. Any spare space shows the background colour. Best for packaging, logos, or anything that must be seen in full. |
| **Background** | A colour shown behind a Contain image or through the see through parts of a PNG. Leave it blank for none. |

Because the shop resizes on demand, you can change a picture's box and fit at any time without uploading it again. Tweak until it looks right.

### A quick rule of thumb

* Square or gently rectangular photos are the safe default.
* Use **Cover** for normal product photos.
* Use **Contain** for anything with packaging, text, or a logo that must not be cut.
* Always write a short, honest description of each picture in its alt text box. It helps search engines and customers using screen readers, and the admin will ask you for it.

---

## Step five: publish and check

When the words and pictures are ready, set the product's **Status** to **Published** and save. It is now live.

Visit your shop in a normal browser tab and check:

* The product shows up in the right category.
* The picture looks how you want.
* The Buy button takes you to the correct payment page.

If something is off, go back into the admin, fix it, and save again. Changes are live immediately.

---

## Arranging your shop

A few tools let you control what customers notice first.

**Featured** lifts a product up on listing pages. Use it for your best sellers.

**Hero featured** puts a product in the big banner across the top of the homepage. Reserve this for one or a few standout pieces.

**Sort order** is a number on each product and each category. Lower numbers come first. Use round numbers like 10, 20, 30 so you can slot something new in between later without renumbering everything.

**Draft versus Published** is your on and off switch. A draft is invisible to customers but saved safely, ready for the day you publish it.

---

## The Media area

Everything you upload collects in **Media**. This is useful when you want to see all your pictures in one place, reuse an image, or tidy up files you no longer need. Most of the time you will add images straight from the product editor and rarely need to come here, but it is there when you want it.

---

## Settings

**Settings** holds the shop wide options. You will set most of these once at the start and rarely touch them again.

### Shop details

* **Site name**: the name of your shop.
* **Site URL**: your shop's full web address.
* **Default currency**: a three letter code such as `GBP`, `USD`, or `EUR`. This is used behind the scenes for search engines.

### Shop mode

This decides what the button on each product does.

* **Checkout mode** (the usual choice): each product shows a Buy button that goes to its payment link. This is what most shops want.
* **Catalogue mode**: instead of a Buy button, every product shows an enquiry action, such as an email link or a contact form. This suits businesses that sell by quote rather than fixed online payment, like a gallery or a consultant. In this mode you set one **enquiry action** for the whole shop, for example `mailto:hello@example.com` or a link to your contact page.

### The secure checkout notice

In checkout mode you can show a small reassurance line under the Buy button. It tells the customer which payment provider handles the payment and that it opens in a new tab. The provider name is worked out automatically from each product's checkout link. You can switch this notice on or off.

### Image quality

Two sliders set how crisp your saved images are, on a scale from 60 to 95. The defaults are a good balance of quality and speed. Higher means sharper but heavier files. You can leave these alone unless you have a reason not to.

### Grid card thumbnails

These control the small pictures in the category and homepage grids only. They do not affect the large image on the product page, which you set per product.

* **Thumbnail proportion**: the shape of the small cards. A value around 240 is square. Lower is wider, higher is taller. The shape scales with the screen, so it looks the same on a phone and a desktop.
* **Thumbnail fit**: Cover crops to fill for a tidy uniform grid, or Contain shows the whole picture.
* **Crop position**: when using Cover, which part of the picture to keep.
* **Background colour**: shown behind images and through transparent areas. A hex colour, or blank for none.

### Layout

* **Categories per row** and **Products per row**: choose 3 or 4 across on wide screens. Narrower screens automatically show fewer so nothing is ever cramped.

### Search engine defaults

These help your shop appear well in search results and when shared on social media.

* **Default meta description**: a sentence describing your whole shop, used on the homepage.
* **Default sharing image**: a picture used when a page has no image of its own.
* **Twitter handle**: your handle, including the `@`.
* **Brand name**: your brand, used in the structured data search engines read. Defaults to your site name if left blank.

### Changing your password

At the bottom of Settings you can set a new password. It must be at least ten characters. Leave the boxes blank to keep your current one. Changing your password signs you out, so you will log back in with the new one.

---

## Removing the footer credit (optional)

Without a licence, Nano Cart shows a small "Powered by Nano Cart" line in the footer. This is normal and completely fine to leave in place.

If you would rather remove it, you can buy a licence for your domain and paste the key into the **Licence** area of the admin. The footer credit then disappears. The check happens entirely on your own server, with no tracking and no contact with anyone. You can buy a licence from the Nano Cart website.

---

## Going live and staying safe

When you have finished a round of edits, the safest thing is to have the admin folder taken off the server. Your shop keeps working perfectly without it: customers browse, view products, and click through to pay exactly as before. The only thing that goes away is the private admin area, and with it any way for someone to try the password while you are not using it.

When you want to make changes again, the admin folder goes back on, you sign in, you work, and it comes off again. Your developer usually handles this part, so a typical routine is:

1. Ask your developer to put the admin back.
2. Make your changes.
3. Tell them you are done so they can take it off again.

---

## Backups

Your entire shop is a set of plain files on the server: the product details, the categories, and the images. There is no database. A simple scheduled copy of the shop folder and its config folder to a safe place is all the backup you need. Your developer sets this up once, and restoring is just copying the files back. It is worth confirming with them that backups are running.

---

## Common questions

**Can I sell different sizes or colours of the same product?**
Not as variants. Nano Cart is built for one item at one price. If you need size or colour options, list them as separate products, or use a fuller shop platform.

**Can customers buy several things in one go?**
No. There is no basket. Each product is a single Buy button that leads straight to its own checkout. This keeps the shop fast and simple, which suits small fixed catalogues.

**Where does the money go?**
Straight to your payment provider. Nano Cart never handles money or card details. It only shows your products and points the Buy button at the checkout page you set up.

**I changed a product but the shop looks the same.**
Refresh the page in your browser. If you edited an image's fit or size, give it a moment, as the new version is prepared the first time it is viewed.

**A product is not showing up.**
Check its status is Published, not Draft, and that it is filed under a category that exists.

**The admin will not load.**
The admin folder is most likely off the server for safety. Ask your developer to put it back so you can sign in.

---

## A simple weekly routine

1. Have the admin put back on the server.
2. Sign in.
3. Add new products as drafts, with photos and checkout links.
4. Preview them on the live shop.
5. Switch the ones you are happy with to Published.
6. Sign out and have the admin taken off again.

That is the whole job. Build your categories once, add products as you make them, and let the shop stay quiet, fast, and safe in between.

---

## Where to go next

* **INSTALL.md**: how the shop is first set up on a server.
* **FORMAT.md**: the exact shape of the files behind the scenes, for the curious or technical.
* **CHANGELOG.md**: what changed in each version.
* The built in **Help** page inside the admin: a quick reference you can read while you work.
