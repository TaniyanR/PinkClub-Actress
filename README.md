# PinkClub-Actress

女優を入口にFANZA作品を探す、女優特化型のアフィリエイトサイトです。フロントデザインと管理機能は [PinkClub-FL](https://github.com/TaniyanR/PinkClub-FL) をベースにしています。

## 公開サイトの構成

グローバルメニューは次の3つに絞ります。

- TOP
- 女優一覧
- しろうと女性一覧

### TOP

- 女優写真をメインに表示
- 女優としろうと女性を混在させてランダム表示
- PCは横6列 × 縦20行
- 1ページ最大120名
- 写真・名前から女優個別ページへ移動
- 同じ女性が両方の対象作品に登場する場合は重複表示しない
- 「女優ピックアップ」などの見出しは表示しない

### 女優一覧 / しろうと女性一覧

- 五十音の「あ・か・さ・た・な・は・ま・や・ら・わ」と A-Z から探せる一覧
- 写真を丸型サムネイルで表示
- 名前をクリックすると女優個別ページへ移動
- しろうと女性は、しろうと動画フロアの商品との関連情報から自動分類

### 女優個別ページ

- 女優写真
- 名前・よみ・誕生日・出身地など取得可能なプロフィール
- 出演作品
- 人気の女優ランキング
- 商品カードの作品リンクはサイト内の商品詳細ページを作らず、FANZAの購入ページへ転送

## API方針

PinkClub-Actress は **女優情報APIがメイン、商品情報APIが補助** です。

### メイン: FANZA 女優情報API

- 女優名
- よみ
- 女優写真
- 誕生日
- 出身地
- その他取得可能なプロフィール情報

公開サイトの中心となる女優データを保存します。

### 補助: FANZA 商品情報API

- 女優個別ページの出演作品
- 女優 / しろうと女性の分類に必要な商品との関連情報
- 商品カード
- FANZA購入ページへのアフィリエイトリンク

商品そのものを主役にはしません。

商品取得対象は `config/config.php` の `dmm.catalog_targets` で管理します。

| site | service | floor | 用途 |
| --- | --- | --- | --- |
| FANZA | digital | videoa | 女優側の出演作品取得（補助） |
| FANZA | digital | videoc | しろうと女性側の出演作品取得（補助） |

## 管理画面のAPI設定

API設定メニューは次の順番です。

1. 女優情報API設定
2. 商品情報API設定（補助）
3. 自動設定

APIID / アフィリエイトIDは女優APIと商品APIで共通利用します。

## 主な機能

- FANZA女優情報の取得・保存
- FANZA商品情報の補助取得・保存
- 複数フロアの順次取得とフロア別offset管理
- 女優一覧・しろうと女性一覧
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
5. 管理画面の「女優情報API設定」でAPI IDとアフィリエイトIDを保存します。
6. 女優情報をテスト取得して保存します。
7. 補助として商品情報APIを同期し、出演作品と分類用の関連データを保存します。

初期管理者情報を使用している場合は、公開前に必ず変更してください。

## 自動取得

公開アクセスではAPI同期を実行しません。自動取得を使う場合はcronから実行してください。

```bash
php /path/to/PinkClub-Actress/scripts/auto_import.php
```

## 主要URL

- TOP: `/`
- 女優一覧: `/actresses.php`
- しろうと女性一覧: `/amateur_actresses.php`
- 女優個別: `/actress.php?id={ID}`
- 管理ログイン: `/public/login0718.php`
- 管理トップ: `/admin/index.php`
- 女優情報API設定: `/admin/api_actresses.php`
- 商品情報API設定（補助）: `/admin/api_items.php`

`/item.php?id={ID}` は商品詳細ページではなく、保存済みの `affiliate_url` を優先して購入ページへ転送します。

## 設定とセキュリティ

- DB接続情報やAPI認証情報をGitへコミットしないでください。
- `config.local.php`、ログ、セッション情報は公開しないでください。
- 管理者パスワードを変更し、HTTPSで運用してください。
- 外部URLへの転送は `http` / `https` のみ許可します。

## クレジット

<a href="https://affiliate.dmm.com/api/" target="_blank" rel="nofollow"><img src="https://p.dmm.co.jp/p/affiliate/web_service/r18_135_17.gif" alt="WEB SERVICE BY FANZA" width="135" height="17"></a>

女優情報・商品情報はDMM/FANZA Affiliate APIを利用します。
