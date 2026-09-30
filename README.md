# PinkClub-Actress

PinkClub-Actress は、FANZAの**女優を入口**にプロフィールと出演作品を紹介する女優特化サイトです。

共通UI・管理・SEO・RSS・アクセス解析などの基盤は [PinkClub-FL](https://github.com/TaniyanR/PinkClub-FL) を基準にし、女優取得と作品取得だけをPinkClub-Actress向けに構成します。

## データ方針

### 女優マスタ

女優登録の正データは **FANZA ActressSearch（女優情報API）だけ**です。

保存する主な情報:

- DMM女優ID
- 女優名 / よみ
- 女優写真
- 誕生日
- 出身地
- 趣味
- バスト / カップ / ウエスト / ヒップ
- 身長
- 血液型

商品情報APIだけを根拠に actresses テーブルへ新しい女性を追加しません。

### 出演作品

作品は、すでに女優情報APIで登録されている女優を起点に取得します。

- article=actress
- article_id=<登録済み女優DMM ID>
- FANZA / digital / videoa

取得した作品はDBへ保存し、女優個別ページの商品カードとして紹介します。

**登録済み女優が1人も紐づかない作品は保存・公開対象にしません。**

過去DBにそのような作品が残っていても、公開一覧・検索・ランキングでは共通フィルタにより除外します。既存データを破壊するための一括削除は行いません。

## 公開サイト

- TOP: 女優写真と女優名を中心に表示
- 女優一覧: 五十音 / A-Z から探せる
- 女優個別:
  - 女優写真
  - プロフィール
  - DBに保存済みの出演作品
  - 人気の女優ランキング
- 商品カード: PinkClub共通カードを利用
- 商品導線: 保存済みアフィリエイトURLを利用

旧 /amateur_actresses.php は互換用に残し、女優一覧へ301リダイレクトします。

## 自動取得

1サイクルの基本処理:

1. 女優情報APIから100人取得・更新
2. プロフィール不足の女優を最大10人補完
3. 登録済み女優を最大5人選択
4. 1女優あたり最大20作品を取得・保存

1サイクルの外部商品API呼び出しは最大5回です。

女優ごとに商品取得offsetをDBへ保存し、同じ女優の出演作品を少しずつ蓄積します。

公開ページ表示中に外部API同期は実行しません。

cron:

    php /path/to/PinkClub-Actress/scripts/auto_import.php

## 管理画面

- サイト設定
- 広告 / コード設定
- 相互リンク / 相互RSS
- 女優・作品 API設定
- 自動取得設定
- アクセス解析
- 固定ページ

APIID / アフィリエイトIDは女優取得と作品取得で共通利用します。

## 主要URL

- TOP: /
- 女優一覧: /actresses.php
- 女優個別: /actress.php?id={ID}
- 管理ログイン: /public/login0718.php
- 管理トップ: /admin/index.php
- 女優・作品 API設定: /admin/api_actresses.php
- 自動設定: /admin/api_auto.php

## 必要環境

- PHP 8.1以上
- MySQL 8.0 または MariaDB 10.5以上
- PDO MySQL
- mbstring
- JSON
- cURL または allow_url_fopen
- Apache / nginx
- cron（自動取得を使う場合）

## セットアップ

1. ファイル一式を配置
2. /public/setup_check.php を開く
3. DB情報を保存してセットアップ
4. /public/login0718.php からログイン
5. 「女優・作品 API設定」でAPIID / アフィリエイトIDを保存
6. 「今すぐ1回実行」またはcronで女優と出演作品を順次取得

DB接続情報・API認証情報・ログ・セッション情報をGitへコミットしないでください。公開環境ではHTTPSを使用してください。

## API

女優情報・商品情報は DMM/FANZA Affiliate API を利用します。

<a href="https://affiliate.dmm.com/api/" target="_blank" rel="nofollow"><img src="https://p.dmm.co.jp/p/affiliate/web_service/r18_135_17.gif" alt="WEB SERVICE BY FANZA" width="135" height="17"></a>
