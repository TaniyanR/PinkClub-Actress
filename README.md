# PinkClub-Actress

女優を入口にFANZA作品を探す、女優特化型のアフィリエイトサイトです。フロントデザインと管理機能は [PinkClub-FL](https://github.com/TaniyanR/PinkClub-FL) をベースにしています。

## 公開サイトの構成

グローバルメニューは次の3つに絞ります。

- TOP
- 女優一覧
- しろうと女優一覧

### TOP

- 女優写真をメインに表示
- 女優としろうと女優を混在させてランダム表示
- PCは横6列 × 縦20行
- 1ページ最大120名
- 写真・名前から女優個別ページへ移動
- 同じ女優が両方の対象作品に登場する場合は重複表示しない

### 女優一覧 / しろうと女優一覧

- 五十音の「あ・か・さ・た・な・は・ま・や・ら・わ」と A-Z から探せる一覧
- 女優写真を丸型サムネイルで表示
- 名前をクリックすると女優個別ページへ移動
- しろうと女優は、しろうと動画フロアの商品に出演する女優として自動分類

### 女優個別ページ

- 女優写真
- 名前・よみ・誕生日・出身地など取得可能なプロフィール
- 出演作品
- 人気の女優ランキング
- 商品カードの作品リンクはサイト内の商品詳細ページを作らず、FANZAの購入ページへ転送

## 利用するAPI

- FANZA 女優情報API
- FANZA 商品情報API

商品取得対象は `config/config.php` の `dmm.catalog_targets` で管理します。

| site | service | floor | 用途 |
| --- | --- | --- | --- |
| FANZA | digital | videoa | 女優側の作品取得 |
| FANZA | digital | videoc | しろうと女優側の作品取得 |

女優・しろうとの分類は商品と女優の関連データを利用するため、商品APIの同期後に一覧へ反映されます。

## 主な機能

- FANZA商品情報の取得・保存
- FANZA女優情報の取得・保存
- 複数フロアの順次取得とフロア別offset管理
- 女優一覧・しろうと女優一覧
- 女優プロフィール・出演作品
- 女優アクセスランキング（日・週・月・年）
- 商品カードからFANZA購入ページへのアフィリエイト導線
- WordPress風の管理画面
- API認証情報保存、テスト取得、cron自動取得
- SEO、OGP、サイトマップ、RSS、アクセス解析

## 必要環境

- PHP 8.1以上
- MySQL 8.0またはMariaDB 10.5以上
- PDO MySQL、mbstring、JSON、cURLまたはallow_url_fopen
- Apache / nginx
- cron（自動取得を使う場合）

XAMPPでも動作確認できます。

## セットアップ

1. ファイル一式をサーバーへ配置します。
2. `/public/setup_check.php` を開きます。
3. DB情報を保存してセットアップを実行します。
4. `/public/login0718.php` からログインします。
5. 管理画面のAPI設定でAPI IDとアフィリエイトIDを保存します。
6. 女優情報APIと商品情報APIの接続を確認します。
7. 商品同期を実行し、女優と出演作品の関連データを保存します。

初期管理者情報を使用している場合は、公開前に必ず変更してください。

## 自動取得

公開アクセスではAPI同期を実行しません。自動取得を使う場合はcronから実行してください。

```bash
php /path/to/PinkClub-Actress/scripts/auto_import.php
```

## 主要URL

- TOP: `/`
- 女優一覧: `/actresses.php`
- しろうと女優一覧: `/amateur_actresses.php`
- 女優個別: `/actress.php?id={ID}`
- 管理ログイン: `/public/login0718.php`
- 管理トップ: `/admin/index.php`
- セットアップ確認: `/public/setup_check.php`

`/item.php?id={ID}` は商品詳細ページではなく、保存済みの `affiliate_url` を優先して購入ページへ転送します。

## 設定とセキュリティ

- DB接続情報やAPI認証情報をGitへコミットしないでください。
- `config.local.php`、ログ、セッション情報は公開しないでください。
- 管理者パスワードを変更し、HTTPSで運用してください。
- 外部URLへの転送は `http` / `https` のみ許可します。

## クレジット

<a href="https://affiliate.dmm.com/api/" target="_blank" rel="nofollow"><img src="https://p.dmm.co.jp/p/affiliate/web_service/r18_135_17.gif" alt="WEB SERVICE BY FANZA" width="135" height="17"></a>

商品情報・女優情報はDMM/FANZA Affiliate APIを利用します。
