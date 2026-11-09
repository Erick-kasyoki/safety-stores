-- psql -U postgres -c "CREATE DATABASE safety_store ENCODING 'UTF8' TEMPLATE template0;"
-- psql -U postgres -d safety_store -f schema.sql
SET client_encoding='UTF8';
-- role: customer (public sign-up) | worker (created by an admin) | admin (admin sign-up page)
CREATE TABLE users(id SERIAL PRIMARY KEY,email TEXT NOT NULL UNIQUE,name TEXT NOT NULL,phone TEXT NOT NULL DEFAULT '',password_hash TEXT NOT NULL,role TEXT NOT NULL DEFAULT 'customer' CHECK(role IN('customer','worker','admin')),active BOOLEAN NOT NULL DEFAULT true,must_change BOOLEAN NOT NULL DEFAULT false,last_login TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX users_role_idx ON users(role);
-- Failed sign-ins, used to slow down password guessing.
CREATE TABLE login_attempts(id SERIAL PRIMARY KEY,k TEXT NOT NULL,at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX login_attempts_idx ON login_attempts(k,at);
CREATE TABLE products(id SERIAL PRIMARY KEY,name TEXT NOT NULL,descr TEXT NOT NULL DEFAULT '',price NUMERIC(10,2) NOT NULL CHECK(price>=0),was NUMERIC(10,2),emoji TEXT NOT NULL DEFAULT '📦',image TEXT NOT NULL DEFAULT '',dept TEXT NOT NULL,stock INT NOT NULL DEFAULT 50 CHECK(stock>=0),sku TEXT NOT NULL DEFAULT '',cost NUMERIC(10,2) CHECK(cost>=0),reorder_level INT NOT NULL DEFAULT 5 CHECK(reorder_level>=0));
CREATE INDEX products_dept_idx ON products(dept);
CREATE TABLE orders(id SERIAL PRIMARY KEY,user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,total NUMERIC(10,2) NOT NULL,status TEXT NOT NULL DEFAULT 'pending',name TEXT NOT NULL,phone TEXT NOT NULL,address TEXT NOT NULL,note TEXT NOT NULL DEFAULT '',payment TEXT NOT NULL,assigned_to INT REFERENCES users(id) ON DELETE SET NULL,updated_at TIMESTAMPTZ,updated_by INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX orders_status_idx ON orders(status);
CREATE TABLE order_items(id SERIAL PRIMARY KEY,order_id INT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,product_id INT REFERENCES products(id) ON DELETE SET NULL,name TEXT NOT NULL,unit_price NUMERIC(10,2) NOT NULL,qty INT NOT NULL CHECK(qty>0));
-- Inventory: every change to stock (sale, restock, adjustment, cancelled order) is logged here.
-- kind: opening | restock | sale (online order) | walkin (sold in store) | adjust | damaged | return (cancelled order)
CREATE TABLE stock_movements(id SERIAL PRIMARY KEY,product_id INT REFERENCES products(id) ON DELETE SET NULL,name TEXT NOT NULL,kind TEXT NOT NULL,qty_change INT NOT NULL,qty_after INT NOT NULL,unit_price NUMERIC(10,2),order_id INT REFERENCES orders(id) ON DELETE SET NULL,note TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX stock_movements_product_idx ON stock_movements(product_id,created_at DESC);
CREATE INDEX stock_movements_created_idx ON stock_movements(created_at DESC);
-- Invoices: status is draft/sent/void; paid, partial and overdue are worked out from the payments and the due date.
CREATE SEQUENCE invoice_seq;
CREATE TABLE recurring_invoices(id SERIAL PRIMARY KEY,customer_name TEXT NOT NULL,customer_email TEXT NOT NULL DEFAULT '',customer_phone TEXT NOT NULL DEFAULT '',customer_address TEXT NOT NULL DEFAULT '',items JSONB NOT NULL,tax_rate NUMERIC(5,2) NOT NULL DEFAULT 0,discount NUMERIC(10,2) NOT NULL DEFAULT 0,notes TEXT NOT NULL DEFAULT '',frequency TEXT NOT NULL CHECK(frequency IN('weekly','monthly','quarterly','yearly')),due_days INT NOT NULL DEFAULT 14,start_date DATE NOT NULL,runs INT NOT NULL DEFAULT 1,next_date DATE NOT NULL,active BOOLEAN NOT NULL DEFAULT true,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE TABLE invoices(id SERIAL PRIMARY KEY,number TEXT NOT NULL UNIQUE,token TEXT NOT NULL UNIQUE,status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN('draft','sent','void')),customer_name TEXT NOT NULL,customer_email TEXT NOT NULL DEFAULT '',customer_phone TEXT NOT NULL DEFAULT '',customer_address TEXT NOT NULL DEFAULT '',order_id INT REFERENCES orders(id) ON DELETE SET NULL,recurring_id INT REFERENCES recurring_invoices(id) ON DELETE SET NULL,issue_date DATE NOT NULL,due_date DATE NOT NULL,tax_rate NUMERIC(5,2) NOT NULL DEFAULT 0,discount NUMERIC(12,2) NOT NULL DEFAULT 0,subtotal NUMERIC(12,2) NOT NULL,tax NUMERIC(12,2) NOT NULL,total NUMERIC(12,2) NOT NULL,notes TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX invoices_status_idx ON invoices(status,due_date);
CREATE TABLE invoice_items(id SERIAL PRIMARY KEY,invoice_id INT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,product_id INT REFERENCES products(id) ON DELETE SET NULL,description TEXT NOT NULL,qty NUMERIC(10,2) NOT NULL CHECK(qty>0),unit_price NUMERIC(10,2) NOT NULL CHECK(unit_price>=0),position INT NOT NULL DEFAULT 0);
CREATE TABLE invoice_payments(id SERIAL PRIMARY KEY,invoice_id INT NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL,paid_on DATE NOT NULL,note TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX invoice_payments_inv_idx ON invoice_payments(invoice_id);
-- Store settings saved from admin.html (override config.php).
CREATE TABLE settings(k TEXT PRIMARY KEY,v TEXT NOT NULL DEFAULT '');
-- Sample prices, descriptions and stock: edit them in admin.html.
INSERT INTO products(name,descr,price,emoji,dept,image) VALUES
('Overalls','Full-body work overalls for workshops, sites and kitchens.',18.00,'🥼','Workwear','img/x-overalls.jpg'),
('Polo T-shirts','Collared work polo shirts, ideal for uniforms.',7.00,'👔','Workwear','img/polo-tshirts.jpg'),
('Round Neck T-shirts','Comfortable crew neck T-shirts for crews and staff.',5.00,'👕','Workwear','img/round-neck-tshirts.jpg'),
('Reflector Vests','High-visibility vests with reflective strips.',6.00,'🦺','Workwear','img/reflector-vests.jpg'),
('Gloves','Work gloves for grip and hand protection.',2.50,'🧤','Workwear','img/gloves.jpg'),
('Gumboots','Waterproof rubber boots for wet and muddy work.',14.00,'👢','Footwear','img/x-gumboots.jpg'),
('Safety Boots','Sturdy work boots for tough environments.',28.00,'🥾','Footwear','img/x-safety-boots.jpg'),
('Kitchen Shoes','Slip-resistant shoes for kitchens and food areas.',12.00,'👟','Footwear','img/kitchen-shoes.jpg'),
('Safety Helmets','Hard hats for head protection on site.',9.00,'⛑️','Head & Face','img/safety-helmets.jpg'),
('Safety Goggles','Clear goggles to shield eyes from dust and splashes.',4.00,'🥽','Head & Face','img/x-safety-goggles.jpg'),
('Face Masks','Disposable masks for dust and hygiene.',8.00,'😷','Head & Face','img/face-masks.jpg'),
('Earmuffs','Ear protection for loud workplaces.',7.50,'🎧','Head & Face','img/x-earmuffs.jpg'),
('Hairnets','Disposable hairnets for food handling and clean areas.',5.00,'👩‍🍳','Head & Face','img/x-hairnets.jpg'),
('Caution Tape','Barrier tape for marking hazards and restricted areas.',4.50,'🚧','Site Safety','img/x-caution-tape.jpg'),
('Safety Harness','Full-body harness for work at height.',30.00,'🧗','Site Safety','img/x-safety-harness.jpg'),
('Fire Extinguisher','Portable extinguisher for offices, shops and sites.',35.00,'🧯','Site Safety','img/x-fire-extinguisher.jpg'),
('First Aid Kits','Stocked kits for minor injuries at work and home.',15.00,'🩹','Site Safety','img/x-first-aid-kits.jpg'),
('Paint Brushes','Brushes for walls, trims and touch-ups.',6.00,'🖌️','Painting','img/x-paint-brushes.jpg'),
('Wire Brushes','Steel bristle brushes for rust and surface prep.',3.50,'🧹','Painting','img/x-wire-brushes.jpg'),
('Spray Paint','Quick-drying aerosol paint for marking and finishing.',4.00,'🎨','Painting','img/x-spray-paint.jpg'),
('Duct Tape','Strong cloth-backed tape for sealing, bundling and repairs.',2.00,'📼','Site Safety','img/duct-tape.jpg'),
('Ear Plugs','Reusable foam and silicone ear plugs in a carry case.',1.50,'👂','Head & Face','img/ear-plugs.jpg'),
('Dust Mask','Reusable dust mask with filter, for dusty work areas.',3.00,'😷','Head & Face','img/dust-mask.jpg'),
('Medical Waste Bins','Colour-coded foot-pedal bins for medical and biohazard waste.',12.00,'🗑️','Site Safety','img/medical-waste-bins.jpg'),
('Branded Hi-Vis Vest (Multi-pocket)','Yellow and black multi-pocket vest with reflective strips, printed with your company name or logo.',12.00,'🦺','Branding','img/branded-vest-multipocket.jpg'),
('Branded Reflector Vest (Printed)','Hi-vis reflector vest printed with your logo and contact details.',8.00,'🦺','Branding','img/branded-vest-printed.jpg'),
('Branded Reflector Vests (Co-operative style)','Bulk hi-vis vests printed with your organisation name and logo.',8.00,'🦺','Branding','img/branded-vest-embroidered.jpg'),
('Branded Safety Vest (Green, Pockets)','Green multi-pocket vest with reflective bands and embroidered badge.',14.00,'🦺','Branding','img/branded-vest-green.jpg'),
('Branded Safety Vest (Khaki, Back Print)','Khaki vest with reflective bands and a large back print for your logo.',14.00,'🦺','Branding','img/branded-vest-khaki.jpg'),
('Branded Safety Helmets (Logo)','White hard hats with your company name or logo applied.',11.00,'⛑️','Branding','img/branded-helmet-print.jpg'),
('Branded Safety Helmets (Full Colour Logo)','White hard hats with a full colour logo print, supplied in bulk.',11.00,'⛑️','Branding','img/branded-helmet-logo.jpg'),
('Branded Helmet and Vest Set','Matching branded helmets and hi-vis vests for your crew.',19.00,'⛑️','Branding','img/branded-helmet-vest-set.jpg'),
('Blue Reflector Vest','Blue vest with yellow reflective strips and chest pockets. Branding available.',7.00,'🦺','Workwear','img/vest-blue-reflector.jpg'),
('Polo T-shirt, Sky Blue','Sky blue polo with navy collar. Branding available.',7.00,'👔','Workwear','img/polo-sky-blue.jpg'),
('Polo T-shirt, Royal Blue / White Collar','Royal blue polo with white collar. Branding available.',7.00,'👔','Workwear','img/polo-royal-blue-white.jpg'),
('Polo T-shirt, White / Green Collar','White polo with green collar. Branding available.',7.00,'👔','Workwear','img/polo-white-green.jpg'),
('Polo T-shirt, Turquoise','Turquoise polo, bagged and ready to brand.',7.00,'👔','Workwear','img/polo-turquoise.jpg'),
('Polo T-shirt, Orange / Black Collar','Orange polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-orange-black.jpg'),
('Polo T-shirt, Mustard','Mustard textured polo. Branding available.',7.00,'👔','Workwear','img/polo-mustard.jpg'),
('Polo T-shirt, Black / White Collar','Black polo with white collar. Branding available.',7.00,'👔','Workwear','img/polo-black-white.jpg'),
('Polo T-shirt, Beige','Beige polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-beige.jpg'),
('Polo T-shirt, Orange / Blue Collar','Orange polo with royal blue collar. Branding available.',7.00,'👔','Workwear','img/polo-orange-blue.jpg'),
('Polo T-shirt, Royal Blue / Black Collar','Royal blue polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-royal-blue-black.jpg'),
('Polo T-shirt, Purple','Purple polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-purple.jpg'),
('Polo T-shirt, Red','Red polo with white collar. Branding available.',7.00,'👔','Workwear','img/polo-red.jpg'),
('Polo T-shirt, Yellow','Yellow polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-yellow.jpg'),
('Polo T-shirt, Lime Green','Hi-vis lime polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-lime.jpg'),
('Polo T-shirts, Pink and Fuchsia','Pink and fuchsia polos with black collar. Branding available.',7.00,'👔','Workwear','img/polo-pink.jpg'),
('Polo T-shirt, Grey','Grey polo with black collar. Branding available.',7.00,'👔','Workwear','img/polo-grey.jpg'),
('Round Neck T-shirt, Red','Red crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-red.jpg'),
('Round Neck T-shirt, Navy','Navy crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-navy.jpg'),
('Round Neck T-shirt, Maroon','Maroon crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-maroon.jpg'),
('Round Neck T-shirt, Black','Black crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-black.jpg'),
('Round Neck T-shirt, Royal Blue','Royal blue crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-royal-blue.jpg'),
('Round Neck T-shirt, Yellow','Yellow crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-yellow.jpg'),
('Round Neck T-shirt, Grey','Grey crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-grey.jpg'),
('Round Neck T-shirt, Pink','Pink crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-pink.jpg'),
('Nitrile Heavy Duty Gloves (Green)','Chemical-resistant green nitrile gloves for industrial work.',4.00,'🧤','Gloves','img/nitrile-heavy-duty-gloves.jpg'),
('PVC Chemical Gloves (Red, Long)','Long red PVC gloves for chemicals, oils and wet work.',5.00,'🧤','Gloves','img/pvc-chemical-gloves-red.jpg'),
('Black Chemical-Resistant Gloves','Heavy-duty black rubber gloves with full chemical protection.',5.50,'🧤','Gloves','img/black-chemical-gloves.jpg'),
('Cut-Resistant PU Gloves (Grey)','PU-coated grey gloves with cut protection and a knit cuff.',3.50,'🧤','Gloves','img/cut-resistant-gloves.jpg'),
('Leather Welding Gloves (Red)','Long red split-leather gloves for welding and hot work.',6.00,'🧤','Gloves','img/leather-welding-gloves.jpg'),
('Latex Coated Gloves (Blue)','Blue latex-coated work gloves with a firm grip.',2.00,'🧤','Gloves','img/latex-coated-gloves-blue.jpg'),
('Latex Coated Gloves (Grey and Blue)','Grey knit gloves with blue latex palm coating.',2.00,'🧤','Gloves','img/latex-coated-gloves-grey.jpg'),
('Nitrile Examination Gloves (Box of 100)','Powder-free blue nitrile examination gloves, textured, sizes XS to L.',9.00,'🧤','Gloves','img/nitrile-exam-gloves.jpg'),
('Latex Powdered Examination Gloves (Box of 100)','Single-use powdered latex examination gloves, ambidextrous.',8.00,'🧤','Gloves','img/latex-exam-gloves.jpg'),
('Handcare Nitrile Medical Gloves (Box)','Powder-free nitrile medical examination gloves, non-sterile.',10.00,'🧤','Gloves','img/handcare-nitrile-gloves.jpg'),
('Disposable PE Gloves (Pack of 100)','Thin disposable polyethylene gloves for food handling and hygiene.',2.00,'🧤','Gloves','img/pe-disposable-gloves.jpg'),
('Industrial Rubber Gloves (Orange Lined)','Flock-lined rubber gloves for cleaning and industrial use.',3.00,'🧤','Gloves','img/industrial-rubber-gloves.jpg'),
('Safety Helmet, Blue','Blue hard hat with adjustable harness.',9.00,'⛑️','Head & Face','img/helmet-blue.jpg'),
('Safety Helmet, Yellow','Yellow hard hat with adjustable harness.',9.00,'⛑️','Head & Face','img/helmet-yellow.jpg'),
('Safety Helmet, Red','Red hard hat with adjustable harness.',9.00,'⛑️','Head & Face','img/helmet-red.jpg'),
('Safety Helmet, Orange','Orange hard hat with adjustable harness.',9.00,'⛑️','Head & Face','img/helmet-orange.jpg'),
('Safety Helmet, Green','Green hard hat with adjustable harness.',9.00,'⛑️','Head & Face','img/helmet-green.jpg'),
('Medical Face Masks (Box)','Boxed 3-ply disposable medical face masks.',4.00,'😷','Head & Face','img/medical-face-masks.jpg'),
('Face Shields','Clear full-face shields for splash and droplet protection.',2.00,'🛡️','Head & Face','img/face-shields.jpg'),
('Welding and Safety Goggles (Assorted)','Clear, tinted and welding goggles for different jobs.',5.00,'🥽','Head & Face','img/goggles-assorted.jpg'),
('Smoked Safety Goggles','Dark-lens goggles for glare and bright light.',4.00,'🥽','Head & Face','img/smoked-safety-goggles.jpg'),
('Hairnets, White (Pack)','White disposable hairnets for kitchens and clean areas.',3.00,'👩‍🍳','Head & Face','img/white-hairnets.jpg'),
('Disposable Shoe Covers (Blue)','Blue overshoe covers for clean rooms and hospitals.',2.00,'👟','Head & Face','img/shoe-covers.jpg'),
('Safety Shoes, Low Cut','Black leather steel-toe safety shoes with grip sole.',24.00,'👟','Footwear','img/safety-shoes-low.jpg'),
('Sport Safety Shoes (Grey)','Lightweight grey sport-style safety shoes.',26.00,'👟','Footwear','img/sport-safety-shoes.jpg'),
('Work Mate Safety Shoes','Black and orange steel-toe safety shoes.',24.00,'👟','Footwear','img/workmate-safety-boots.jpg'),
('Suede Safety Shoes (Beige/Yellow)','Suede safety shoes with yellow laces and protective toe.',26.00,'👟','Footwear','img/suede-safety-shoes.jpg'),
('White Gumboots','White waterproof boots for food plants and wet areas.',14.00,'👢','Footwear','img/white-gumboots.jpg'),
('Overalls, Grey','Grey work overalls with contrast pockets.',20.00,'🥼','Workwear','img/overalls-grey.jpg'),
('Navy Boiler Suit','Plain navy one-piece boiler suit.',18.00,'🥼','Workwear','img/boiler-suit-navy.jpg'),
('Dust Coat, Maroon','Long-sleeve maroon dust coat for stores and workshops.',10.00,'🥼','Workwear','img/dust-coat-maroon.jpg'),
('Dust Coat, Green','Long-sleeve green dust coat.',10.00,'🥼','Workwear','img/dust-coat-green.jpg'),
('Dust Coat, Navy Blue','Long-sleeve navy blue dust coat.',10.00,'🥼','Workwear','img/dust-coat-navy.jpg'),
('Dust Coat, Red','Long-sleeve red dust coat.',10.00,'🥼','Workwear','img/dust-coat-red.jpg'),
('Dust Coat, Orange','Long-sleeve orange dust coat.',10.00,'🥼','Workwear','img/dust-coat-orange.jpg'),
('Maroon Reflector Vest','Maroon zip vest with reflective bands. Branding available.',6.00,'🦺','Workwear','img/vest-maroon.jpg'),
('Royal Blue Reflector Vest (ID Pocket)','Royal blue vest with reflective bands and ID pocket.',7.00,'🦺','Workwear','img/vest-royal-blue-id.jpg'),
('Hi-vis Yellow Multi-pocket Vest','Yellow vest with grey reflective bands and several pockets.',10.00,'🦺','Workwear','img/vest-yellow-multipocket.jpg'),
('Lime Reflector Vest','Lime green zip vest with reflective bands. Branding available.',6.00,'🦺','Workwear','img/vest-lime.jpg'),
('Round Neck T-shirt, Beige','Beige crew neck T-shirt. Branding available.',5.00,'👕','Workwear','img/tee-beige.jpg'),
('Ladies Vest Top, Pink','Pink cotton ladies vest top.',4.00,'👚','Workwear','img/vest-top-pink.jpg'),
('Ladies Vest Top, Red','Red cotton ladies vest top.',4.00,'👚','Workwear','img/vest-top-red.jpg'),
('Ladies Vest Top, Fuchsia','Fuchsia cotton ladies vest top.',4.00,'👚','Workwear','img/vest-top-fuchsia.jpg'),
('Bucket Hats (Assorted Colours)','Cotton bucket hats in many colours. Branding available.',3.00,'👒','Workwear','img/bucket-hats.jpg'),
('Reflective Rain Trousers','Navy waterproof trousers with reflective strips.',12.00,'🌧️','Workwear','img/rain-trousers.jpg'),
('Branded T-shirt (Orange, Printed Logo)','Orange T-shirt printed with your logo.',8.00,'👕','Branding','img/branded-tshirt-orange.jpg'),
('Branded T-shirt (Navy, Printed Logo)','Navy T-shirt printed with your logo.',8.00,'👕','Branding','img/branded-tshirt-navy.jpg'),
('Branded Rain Jacket (Embroidered)','Hooded rain jacket with your logo embroidered.',22.00,'🧥','Branding','img/branded-rain-jacket.jpg'),
('Branded Reflective Jacket','Grey work jacket with reflective strips and your logo on the back.',20.00,'🧥','Branding','img/branded-reflective-jacket.jpg'),
('Safety Harness, Hi-vis Green','Full-body harness with hi-vis green straps.',28.00,'🧗','Site Safety','img/harness-hivis-green.jpg'),
('Safety Harness with Lanyard (Yellow)','Yellow full-body harness supplied with a lanyard.',32.00,'🧗','Site Safety','img/harness-lanyard-yellow.jpg'),
('Safety Harness (Orange)','Orange full-body harness for work at height.',26.00,'🧗','Site Safety','img/harness-orange.jpg'),
('Barrier Tape, Red and White','Red and white hazard barrier tape rolls.',4.50,'🚧','Site Safety','img/barrier-tape-red-white.jpg'),
('Reflective Warning Triangle','Reflective roadside warning triangle.',6.00,'⚠️','Site Safety','img/warning-triangle.jpg'),
('Wet Floor Caution Signs','Yellow fold-out caution stands: wet floor, work in progress.',10.00,'⚠️','Site Safety','img/wet-floor-signs.jpg'),
('Sanitary Bins (Foot Pedal)','White foot-pedal bins with teal lid for washrooms.',12.00,'🗑️','Site Safety','img/sanitary-bins.jpg'),
('Waste Bins (Grey/Blue Lid)','Swing and flip-top waste bins for offices and washrooms.',14.00,'🗑️','Site Safety','img/waste-bins-grey-blue.jpg'),
('Cable Ties (White)','Strong white nylon cable ties.',2.00,'🔗','Site Safety','img/cable-ties.jpg'),
('Cloth Tape (Assorted Colours)','Coloured cloth tape for marking, binding and repairs.',2.00,'📼','Site Safety','img/cloth-tape-assorted.jpg'),
('Toilet Signs (Ladies/Gents)','Blue ladies and gents door signs.',3.00,'🚻','Safety Signs','img/sign-toilets.jpg'),
('Fire Assembly Point Sign','Green fire assembly point sign.',4.00,'🔥','Safety Signs','img/sign-fire-assembly.jpg'),
('Site Safety Sign Set','Pack of 8 printed site safety signs.',15.00,'🪧','Safety Signs','img/sign-site-safety-set.jpg'),
('Falling Objects Warning Sign','Yellow triangle warning sign for falling objects.',4.00,'⚠️','Safety Signs','img/sign-falling-objects.jpg'),
('Men at Work Caution Sign','Caution sign for works in progress.',4.00,'⚠️','Safety Signs','img/sign-men-at-work.jpg'),
('Mandatory PPE Sign','Sign listing the PPE to be worn beyond this point.',4.00,'🪧','Safety Signs','img/sign-ppe-mandatory.jpg'),
('Washroom Signs (Gents/Ladies, Green)','Green gents and ladies washroom signs.',3.00,'🚻','Safety Signs','img/sign-washrooms-green.jpg'),
('No Access Sign','No access to unauthorised persons sign.',4.00,'⛔','Safety Signs','img/sign-no-access.jpg'),
('Hacksaw','Metal-cutting hacksaw frame with blade.',6.00,'🪚','Tools & Hardware','img/hacksaw.jpg'),
('Combination Pliers (Red Handle)','Combination pliers with red insulated handles.',4.00,'🔧','Tools & Hardware','img/pliers-red-handle.jpg'),
('Pliers (Orange Handle)','Heavy-duty pliers with orange grip handles.',4.50,'🔧','Tools & Hardware','img/pliers-orange-handle.jpg'),
('Ring Spanners','Chrome ring spanners in assorted sizes.',8.00,'🔧','Tools & Hardware','img/spanners-ring.jpg'),
('Voltage Tester Screwdrivers','Mains voltage tester screwdrivers.',1.50,'🔌','Tools & Hardware','img/voltage-testers.jpg'),
('Flap Discs (Maroon)','Abrasive flap discs for grinding and finishing metal.',1.50,'⚙️','Tools & Hardware','img/flap-discs-maroon.jpg'),
('Flap Discs (Green)','Abrasive flap discs for grinding and finishing metal.',1.50,'⚙️','Tools & Hardware','img/flap-discs-green.jpg'),
('Spray Paint, Yellow','Quick-drying aerosol paint in yellow.',4.00,'🎨','Painting','img/spray-yellow.jpg'),
('Spray Paint, Chrome Silver','Aerosol chrome silver paint.',4.50,'🎨','Painting','img/spray-chrome.jpg'),
('Spray Paint, Blue','Quick-drying aerosol paint in blue.',4.00,'🎨','Painting','img/spray-blue.jpg'),
('Spray Paint, Green','Quick-drying aerosol paint in green.',4.00,'🎨','Painting','img/spray-green.jpg'),
('Spray Paint, Gold','Aerosol gold paint.',4.50,'🎨','Painting','img/spray-gold.jpg'),
('Spray Paint, White','Quick-drying aerosol paint in white.',4.00,'🎨','Painting','img/spray-white.jpg'),
('Dashboard Shine Spray (with Air Freshener)','Cockpit and dashboard polish spray in several scents.',4.00,'🚗','Car Care','img/dashboard-shine.jpg'),
('Gumboots, Black (Tall)','Tall black rubber gumboots for wet and muddy work.',16.00,'👢','Footwear','img/gumboots-black-tall.jpg'),
('Gumboots, Black (Short)','Short black rubber gumboots with a grip sole.',13.00,'👢','Footwear','img/gumboots-black-short.jpg'),
('Double-sided Respirator','Half-mask respirator with two filter cartridges for dust, fumes and vapours.',14.00,'😷','Head & Face','img/respirator-double.jpg'),
('Single-sided Respirator Mask','Half-mask respirator with a single filter cartridge.',10.00,'😷','Head & Face','img/respirator-single.jpg');
INSERT INTO stock_movements(product_id,name,kind,qty_change,qty_after,note) SELECT id,name,'opening',stock,stock,'Opening stock' FROM products;

-- ===== Expense Tracker and Employee Leave Management =====
-- Expenses: every cost of running the business. Each category has an optional monthly budget (0 = no budget).
CREATE TABLE IF NOT EXISTS expense_categories(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,budget NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(budget>=0));
CREATE TABLE IF NOT EXISTS expenses(id SERIAL PRIMARY KEY,spent_on DATE NOT NULL,category TEXT NOT NULL REFERENCES expense_categories(name) ON UPDATE CASCADE ON DELETE RESTRICT,description TEXT NOT NULL DEFAULT '',vendor TEXT NOT NULL DEFAULT '',amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL DEFAULT 'Cash',reference TEXT NOT NULL DEFAULT '',user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS expenses_date_idx ON expenses(spent_on DESC);
CREATE INDEX IF NOT EXISTS expenses_cat_idx ON expenses(category);
INSERT INTO expense_categories(name) VALUES('Rent'),('Salaries'),('Utilities'),('Transport'),('Stock purchases'),('Marketing'),('Repairs & maintenance'),('Other') ON CONFLICT(name) DO NOTHING;
-- Leave: types with a yearly allowance (0 = no limit), and the requests workers make. status: pending | approved | rejected | cancelled
CREATE TABLE IF NOT EXISTS leave_types(id SERIAL PRIMARY KEY,name TEXT NOT NULL UNIQUE,days_per_year INT NOT NULL DEFAULT 0 CHECK(days_per_year>=0),paid BOOLEAN NOT NULL DEFAULT true,active BOOLEAN NOT NULL DEFAULT true);
CREATE TABLE IF NOT EXISTS leave_requests(id SERIAL PRIMARY KEY,user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,type_id INT NOT NULL REFERENCES leave_types(id) ON DELETE RESTRICT,start_date DATE NOT NULL,end_date DATE NOT NULL,days NUMERIC(5,1) NOT NULL CHECK(days>0),reason TEXT NOT NULL DEFAULT '',status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN('pending','approved','rejected','cancelled')),decided_by INT REFERENCES users(id) ON DELETE SET NULL,decided_at TIMESTAMPTZ,decision_note TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(end_date>=start_date));
CREATE INDEX IF NOT EXISTS leave_requests_user_idx ON leave_requests(user_id,start_date);
CREATE INDEX IF NOT EXISTS leave_requests_status_idx ON leave_requests(status,start_date);
INSERT INTO leave_types(name,days_per_year,paid) VALUES('Annual leave',21,true),('Sick leave',14,true),('Compassionate leave',5,true),('Unpaid leave',0,false) ON CONFLICT(name) DO NOTHING;

-- ===== Sales Management module (same as migrate_sales.sql) =====
CREATE SEQUENCE IF NOT EXISTS sale_seq;
-- status: completed | returned (everything sent back) | cancelled. Sales are never deleted.
-- total = amount charged (incl. tax); returned = value of goods sent back (incl. tax); paid = money kept (payments minus refunds paid out).
CREATE TABLE IF NOT EXISTS sales(
 id SERIAL PRIMARY KEY,receipt_no TEXT NOT NULL UNIQUE,idem_key TEXT NOT NULL UNIQUE,
 cashier_id INT REFERENCES users(id) ON DELETE SET NULL,
 customer_name TEXT NOT NULL DEFAULT '',customer_phone TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'completed' CHECK(status IN('completed','returned','cancelled')),
 subtotal NUMERIC(12,2) NOT NULL CHECK(subtotal>=0),discount NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(discount>=0),
 tax_rate NUMERIC(5,2) NOT NULL DEFAULT 0,tax NUMERIC(12,2) NOT NULL DEFAULT 0,total NUMERIC(12,2) NOT NULL CHECK(total>=0),
 returned NUMERIC(12,2) NOT NULL DEFAULT 0,paid NUMERIC(12,2) NOT NULL DEFAULT 0,
 method TEXT NOT NULL DEFAULT '',note TEXT NOT NULL DEFAULT '',oversold BOOLEAN NOT NULL DEFAULT false,
 cancel_reason TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sales_created_idx ON sales(created_at DESC);
CREATE INDEX IF NOT EXISTS sales_cashier_idx ON sales(cashier_id,created_at DESC);
CREATE TABLE IF NOT EXISTS sale_items(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 product_id INT REFERENCES products(id) ON DELETE SET NULL,name TEXT NOT NULL,sku TEXT NOT NULL DEFAULT '',
 unit_price NUMERIC(10,2) NOT NULL CHECK(unit_price>=0),qty INT NOT NULL CHECK(qty>0),
 discount NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK(discount>=0),net NUMERIC(12,2) NOT NULL CHECK(net>=0),
 stock_taken INT NOT NULL DEFAULT 0,restocked INT NOT NULL DEFAULT 0,returned_qty INT NOT NULL DEFAULT 0,returned_net NUMERIC(12,2) NOT NULL DEFAULT 0);
CREATE INDEX IF NOT EXISTS sale_items_sale_idx ON sale_items(sale_id);
CREATE INDEX IF NOT EXISTS sale_items_product_idx ON sale_items(product_id);
CREATE TABLE IF NOT EXISTS sale_payments(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 amount NUMERIC(12,2) NOT NULL CHECK(amount>0),method TEXT NOT NULL,reference TEXT NOT NULL DEFAULT '',
 user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_payments_sale_idx ON sale_payments(sale_id);
CREATE TABLE IF NOT EXISTS sale_returns(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 value NUMERIC(12,2) NOT NULL,refund NUMERIC(12,2) NOT NULL DEFAULT 0,refund_method TEXT NOT NULL DEFAULT '',
 restock BOOLEAN NOT NULL DEFAULT true,cancel BOOLEAN NOT NULL DEFAULT false,reason TEXT NOT NULL DEFAULT '',
 user_id INT REFERENCES users(id) ON DELETE SET NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_returns_sale_idx ON sale_returns(sale_id);
CREATE TABLE IF NOT EXISTS sale_return_items(
 id SERIAL PRIMARY KEY,return_id INT NOT NULL REFERENCES sale_returns(id) ON DELETE CASCADE,
 sale_item_id INT NOT NULL REFERENCES sale_items(id) ON DELETE CASCADE,qty INT NOT NULL CHECK(qty>0),restocked INT NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS sale_audit(
 id SERIAL PRIMARY KEY,sale_id INT NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
 user_id INT REFERENCES users(id) ON DELETE SET NULL,action TEXT NOT NULL,detail TEXT NOT NULL DEFAULT '',created_at TIMESTAMPTZ NOT NULL DEFAULT now());
CREATE INDEX IF NOT EXISTS sale_audit_sale_idx ON sale_audit(sale_id);
-- Give the limited website login (see restrict_postgres.sql) rights on the new tables, when that login exists.
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='store_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;
  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
 END IF;
END $$;

-- ===== Staff live chat (same as migrate_chat.sql) =====
CREATE TABLE IF NOT EXISTS chat_messages(
 id SERIAL PRIMARY KEY,sender_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,recipient_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 body TEXT NOT NULL CHECK(length(body)BETWEEN 1 AND 2000),read_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT now(),CHECK(sender_id<>recipient_id));
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS reply_to INT REFERENCES chat_messages(id) ON DELETE SET NULL;
-- File attachments (Attach button): the file itself is stored in app/chat_files, only its details are kept here
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS file_path TEXT, ADD COLUMN IF NOT EXISTS file_name TEXT, ADD COLUMN IF NOT EXISTS file_type TEXT, ADD COLUMN IF NOT EXISTS file_size INT;
CREATE INDEX IF NOT EXISTS chat_pair_idx ON chat_messages(sender_id,recipient_id,id);
CREATE INDEX IF NOT EXISTS chat_unread_idx ON chat_messages(recipient_id,read_at);
-- Per-person "clear chat": hides a conversation's older messages from ONE person only (the other person still sees them)
CREATE TABLE IF NOT EXISTS chat_clears(
 user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,peer_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 upto INT NOT NULL DEFAULT 0,PRIMARY KEY(user_id,peer_id));
DO $$ BEGIN
 IF EXISTS(SELECT 1 FROM pg_roles WHERE rolname='store_app') THEN
  GRANT SELECT,INSERT,UPDATE,DELETE ON ALL TABLES IN SCHEMA public TO store_app;
  GRANT USAGE,SELECT ON ALL SEQUENCES IN SCHEMA public TO store_app;
 END IF;
END $$;
