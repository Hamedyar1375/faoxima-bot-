# بکاپ و بازیابی (GitHub)

مخزن خصوصی: `git@github.com:Hamedyar1375/faoxima-bot-.git` — بکاپ خارج از سرور.
اتصال با SSH Deploy Key (`/root/.ssh/id_ed25519_github_faoxima`، فقط این ریپو).

## ابزار روزمره — `tools/backup.sh`

| دستور | کار |
|---|---|
| `tools/backup.sh snapshot "توضیح"` | کامیت تغییرات سورس + تگ `pre-YYYYMMDD-HHMM` + پوش به گیت‌هاب |
| `tools/backup.sh rescue <tag>` | برگرداندن فایل‌های tracked به نسخه همان snapshot |
| `tools/backup.sh list` | لیست ۲۰ snapshot آخر |

**قاعده:** قبل از هر جلسه تغییر/آپدیت ← `snapshot`؛ بعد از تایید تست‌ها ← کامیت معمولی + `git push`.

## سناریوهای بازیابی

### ۱) یک کامیت خراب (بقیه سالم)
```bash
git log --oneline -10          # پیدا کردن کامیت خراب
git revert <sha>               # ساخت کامیت معکوس (امن — تاریخچه حفظ می‌شود)
git push origin main
```

### ۲) کل آپدیت خراب (بازگشت به نقطه سالم)
```bash
tools/backup.sh list                     # انتخاب تگ سالم
tools/backup.sh rescue pre-YYYYMMDD-HHMM # بازگردانی فایل‌ها
# سپس: تست → commit → push
```
فایل‌های runtime (لاگ‌ها، lockها، `.env`، `config.php`) خارج از گیت هستند و دست نمی‌خورند.

### ۳) بازسازی کامل روی سرور جدید
```bash
git clone git@github.com:Hamedyar1375/faoxima-bot-.git /opt/faoxima
# سپس دستی: کپی .env و config.php + import دیتابیس + ساخت پوشه‌های logs/ و cronbot/.runtime/
```

## قوانین امنیتی

- ریپو **private** بماند.
- هرگز به گیت اضافه نشوند: `.env`، `config.php`، `logs/`، `error_log`، lockها، فایل‌های `.bak` (همه در `.gitignore` هستند).
- قبل از پوش بزرگ: `git grep -E "[0-9]{8,10}:[A-Za-z0-9_-]{30,45}"` باید خالی باشد (اسکن توکن).
- بکاپ دیتابیس جدا از گیت است (mysqldump شبانه — در جلسه جداگانه).
