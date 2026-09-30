# PinkClub-Actress

PinkClub-FL を共通基盤として、女優情報を入口にFANZA作品を紹介する女優特化型サイトです。

## 基本方針

- 共通UI・管理画面・SEO・OGP・RSS・アクセス解析・セキュリティ・キャッシュ等は PinkClub-FL を基準にする
- サービス固有部分として、FANZA女優情報APIによる女優マスタを保持する
- 商品APIは登録済み女優の出演作品を補助取得する
- 商品APIだけを根拠に女優を新規登録しない
- **女優情報APIで登録されている女優に紐付かない作品は保存・公開しない**
- 既存DBに残る対象外作品は同期時に段階的に整理する

## 公開サイト

- TOP: 登録済み女優をランダム表示
- 女優一覧: 五十音 / A-Z から女優を探す
- 女優個別: 写真・プロフィール・出演作品・女優ランキング
- 作品一覧 / 検索 / 作品詳細: PinkClub-FL の商品表示基盤を使用
- 商品カード・作品詳細からFANZAアフィリエイトリンクへ誘導

公開対象の女優は数値DMM女優IDを持つ女優情報API由来のデータです。
旧「しろうと女性一覧」は女優一覧へ301転送します。

## API取得

1サイクルの基本処理:

1. 女優情報API: 最大100人
2. 女優画像補完: 最大10人
3. videoa通常作品: 最大100作品を確認し、登録済み女優が出演する作品だけ保存
4. まだ作品が無い登録女優: 最大10女優 × 最大10作品を女優ID指定で補完
5. 登録女優に紐付かない既存作品: 最大500件を段階整理

公開ページの表示時には外部API同期を行いません。自動同期はcronを利用します。

```bash
php /path/to/PinkClub-Actress/scripts/auto_import.php
```

## 商品保存条件

通常の商品同期では、商品APIの出演者情報に **すでに actresses テーブルへ登録済みの数値DMM女優ID** が含まれる作品だけを保存対象にします。

女優個別同期では、登録済み女優のDMM女優IDを `article=actress` / `article_id=<ID>` で直接検索します。
商品API側の出演者ID表現が異なる場合でも、検索対象の登録済み女優との関係を `item_actresses` に保存します。

## PinkClub-FLから同期した共通基盤

- 管理画面UI
- SEO / 動的Meta Description
- meta rating="adult"
- OGP / Twitter Card / JSON-LD
- IndexNow
- robots.txt / Sitemap
- RSS / 相互RSS表示
- 相互リンク
- 内蔵アクセス解析
- 検索設定
- 固定ページ
- 広告・コード設定
- 公開ページキャッシュ
- ログイン / パスワード再設定
- セットアップ / migration
- セキュリティ基盤
- 商品一覧 / 検索 / 商品詳細
- サンプル画像UI
- PC / スマートフォン対応

## 主要URL

- TOP: `/`
- 女優一覧: `/actresses.php`
- 女優個別: `/actress.php?id={ID}`
- 作品一覧: `/items.php`
- 作品個別: `/item.php?id={ID}`
- 検索: `/search.php?q=...`
- 管理ログイン: `/public/login0718.php`
- 女優・作品API設定: `/admin/api_actresses.php`
- 自動設定: `/admin/api_auto.php`
- SEO・IndexNow: `/admin/search_settings.php`

## セットアップ

1. ファイル一式をサーバーへ配置
2. `/public/setup_check.php` を開く
3. DB情報を保存してセットアップ
4. `/public/login0718.php` からログイン
5. 「女優・作品API設定」でAPI ID / アフィリエイトIDを保存
6. cronまたは「今すぐ1回実行」で女優と出演作品を取得

APIキー・DBパスワード・セッション情報等の秘密情報はGitへ保存しません。
