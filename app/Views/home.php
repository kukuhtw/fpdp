<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title ?? 'FPDP') ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            background: #f4f7fb;
            color: #1f2937;
        }
        .container {
            max-width: 960px;
            margin: 80px auto;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        h1 {
            font-size: 42px;
            margin: 0 0 12px;
        }
        p {
            font-size: 18px;
            color: #4b5563;
        }
        .badge {
            display: inline-block;
            background: #eef2ff;
            color: #4338ca;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="badge">FPDP</div>
        <h1><?= htmlspecialchars($heading ?? 'Personal Digital Home') ?></h1>
        <p><?= htmlspecialchars($subtitle ?? 'Your domain becomes your digital home.') ?></p>
        <p>Local profile, social feed, federation, commerce, and payment gateway abstraction are all planned around a provider-agnostic architecture.</p>
    </div>
</body>
</html>
