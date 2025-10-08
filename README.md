# نصب و راه‌اندازی Panel روی aaPanel

**نکته مهم:** 

قبل از نصب Panel، **ابتدا Xboard** را به‌صورت کامل نصب کنید.

راهنمای رسمی نصب Xboard روی aaPanel: 

> https://github.com/cedar2025/Xboard/blob/master/docs/en/installation/aapanel.md


پس از اتمام نصب Xboard، مراحل زیر را برای نصب Panel انجام دهید.

---

## 1) Create Website (aaPanel)
1. وارد مسیر زیر شوید:
   
**aaPanel → Website → Add site**

2. موارد زیر را تنظیم کنید :
- **Domain:** دامنهٔ سایت
- **Database:** **Not create**
- **PHP Version:** **8.2**
3. سایت را ایجاد کنید.

---

## 2) Deploy Panel

### ورود به دایرکتوری سایت در ترمینال
```bash
cd /www/wwwroot/your-domain
```

### پاک‌سازی فایل‌های پیش‌فرض (در صورت وجود)
```bash
chattr -i .user.ini || true
rm -rf .htaccess 404.html 502.html index.html .user.ini
```
### گرفتن پروژه روی دایرکتوری سایت

```bash
git clone https://github.com/rahavard01/Panel.git .
```

### اجرای اسکریپت نصب
```bash
bash install.sh
```

---

## 3) Site Configuration (aaPanel)
 از قسمت وبسایت ، سایت خود را انتخاب کنید و **Running directory** را روی **`/public`** قرار دهید.
 
 در URL rewrite متن زیر را اضافه کنید:

```bash
location /downloads {
}

location / {  
    try_files $uri $uri/ /index.php$is_args$query_string;  
}

location ~ .*\.(js|css)?$ {
    expires      1h;
    error_log off;
    access_log /dev/null; 
}
```

## 4) Background Services 


برای اجرای زمان‌بندی‌ها از قسمت Cron تنظیمات زیر را انجام دهید:

- **Task type:** Shell Script
- **Task Name:** panel-schedule
- **Execute cycle:** N Minutes - 1 Minutes
- **Execute user:** `www`
- **Script Content:**
  ```bash
  php /www/wwwroot/your-domain/artisan schedule:run
  ```

---

## راهنمای پارامترها در زمان نصب 
در زمان اجرای `install.sh`، ورودی‌های زیر پرسیده می‌شود:

| **Prompt (English)** | **توضیح** | **مثال** | **Required** |
|:---:|:---:|:---:|:---:|
| **APP URL** | آدرس کامل سایت | `https://panel.example.com` | Yes |
| **DB HOST [127.0.0.1]** | آدرس دیتابیس | `127.0.0.1` | Yes |
| **DB PORT [3306]** | پورت دیتابیس | `3306` | Yes |
| **DB DATABASE** | نام دیتابیس | `panel_db` | Yes |
| **DB USERNAME** | کاربر دیتابیس | `panel_user` | Yes |
| **DB PASSWORD** | پسورد دیتابیس | `********` | Yes |
| **ADMIN EMAIL** | ایمیل ورود ادمین | `admin@example.com` | Yes |
| **ADMIN PASSWORD** | پسورد ورود ادمین | `StrongPassword! در زمان تایپ روی سرور قابل دیدن نیست` | Yes |
| **ADMIN PANEL CODE** | کدی که اکانت‌ها با آن ساخته می‌شوند | `ABC123` | Yes |


---

## بروزرسانی (Maintenance Guide)

### Update Script
```bash
cd /www/wwwroot/your-domain
bash update.sh
```

## مرحله پایانی

بعد از نصب و اجرای پروژه، از قسمت **Settings** در پنل، **تنظیمات اولیه ** را قبل از استفاده انجام دهید (تعرفه ها، ، تنظیمات کارت و… ).
لازم به ذکر است قبل از انجام تنظیمات پنل ابتدا تنظیمات xboard را انجام دهید تا برای پنل به مشکل برخورد نکنید.

تنظیمات الزامی xboard:

- **Site Setting** > **Subscribe URL**
- **Subscription setting** > **Subscription Path**
- **Plan Management** > اضافه کردن پلن ها برای اکانت تست - اکانت یک ماهه - اکانت سه ماهه - اکانت شش ماهه - اکانت یک ساله
 



