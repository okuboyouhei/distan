# CLAUDE.md

このファイルは、AI エージェントが Distan のコードを扱うときに読むためのものです。人間向けの説明は `README.md`、管理画面のビジュアルデザイン（配色・タイポグラフィ・世界観）は `DESIGN.md` にあります。

## このプラグインは何か

WordPress を制作環境として使い、納品できる静的 HTML を書き出すプラグイン。HTML 納品案件のための道具であり、**本番サーバーで動かすためのものではありません**。

旧称 HengePress。v0.9.5 で Distan に改名しました。コード中に `henge` が残っていたら、それは改名漏れです。

## 設計上の前提（変更するときは必ず確認すること）

### 1. 生成はループバック HTTP で行う

1 プロセス内で N ページぶんテンプレートを回す方式は**採用しません**。WordPress は 1 リクエスト 1 ページを前提としており、`$wp_query` や `$post` のグローバル、`wp_head` の一度きりのアクション、enqueue 済みフラグが 2 ページ目以降で壊れるためです。

この方式のおかげで、`get_header()` / `get_template_part()` / テンプレート階層 / 条件分岐がすべて通常どおり動きます。ここは崩さないでください。

### 2. 環境を問わない

- DB に直接 SQL を書かない（`WP_Query` と WP API のみ）
- `exec()` や外部バイナリを呼ばない
- Composer の依存を持たない
- 外部 API を呼ばない

唯一の環境依存は**ループバック通信**です。`Distan_Env` がこれを実測します。

### 3. 上書き運用に最適化する

生成物は FTP で本番に上書きされる前提です。したがって:

- **ファイル名は変えない**（キャッシュバスティングはクエリ文字列で行う。ファイル名ハッシュは上書きできなくなるため却下済み）
- **本番のファイルには触れない**（削除は報告のみ）
- 出力ディレクトリの掃除は自動で行う（再生成可能なので）

### 4. クリーナーは生成リクエスト時のみ作動する

`Distan_Cleaner::is_render_request()` が共有シークレット（`X-Distan-Render` ヘッダ）で判定します。通常の表示・プレビュー・管理画面には一切干渉しません。

**この判定が壊れると、生成は成功したように見えるのに出力だけ汚れます。** 無言の失敗なので、変更時は生成物に `<meta name="generator">` が出ていないことを必ず確認してください。

## クラス構成

| クラス | 責務 |
| --- | --- |
| `Distan` | ブートストラップ、設定の既定値と読み出し |
| `Distan_Paths` | パス封じ込め。破壊的なファイル操作は必ずここを通す |
| `Distan_Env` | 環境チェック。**状態を変えてはいけない**（報告のみ） |
| `Distan_Cleaner` | クリーン HTML 出力。head アクションの除去とバッファ処理 |
| `Distan_Urls` | URL 変換・パス平坦化・クエリ保持。**変換レイヤーの本体** |
| `Distan_Collector` | 生成対象の列挙 |
| `Distan_Generator` | 生成・アセットコピー・掃除・リンク監査・マニフェスト |
| `Distan_Report` | 差分 Markdown と ZIP |
| `Distan_Markdown` | AI ツール向けの本文抽出と `content.md` 生成（選択制） |
| `Distan_Admin` | 管理画面とダウンロード配信 |
| `Distan_Ajax` | AJAX エンドポイント |

`normalize()` `is_contained()` などのパス関数は **`Distan_Paths` にあります**。他クラスから `self::normalize()` と書くと未定義メソッドエラーになります（実際に踏みました）。

## やってはいけないこと

- **`dist/` にプラグインのファイルを置かない。** `dist/` は納品物のルートです。ディレクトリ一覧封じの `index.php` を置いて、納品 ZIP に混入させたことがあります
- **実行可能な拡張子を納品物にコピーしない。** `wp-comments-post.php` がコメントフォームの `action` 経由で混入したことがあります（`distan_blocked_extensions` で除外）
- **`noindex` を焼き込まない。** 開発環境は「検索エンジンでの表示を許可しない」が有効になっていることが多く、そのまま納品すると本番が検索結果から消えます。既定では常に除去し、「残す」を選んだ場合は管理画面に警告を出します
- **リンク先にファイル名を省略しない。** `../about/` は Web サーバーのインデックス機能に依存するため、`file://` で開くと壊れます。必ず `../about/index.html` と書きます
- **エラーがある実行では出力ディレクトリを掃除しない。** 途中で失敗した実行のファイル一覧は、サイトの内容を正しく表していません

## 変換レイヤーの注意点

URL 変換が見ているのは次の属性だけです。

`href` / `src` / `srcset` / `imagesrcset` / `action` / `poster` / `data-src` / CSS の `url()`

**属性以外に URL が現れる場所は個別対応が必要です。** 実際に取りこぼした例:

- `<script type="importmap">` — JSON が `<script>` の中にあるため属性走査に引っかからない
- `speculationrules` — 同上（現在は除去している）
- `/*# sourceURL=... */` — インライン CSS のコメント（現在は除去している）

パス平坦化は 2 段階です。

```
wp-content/themes/mytheme/assets/img/x  →  assets/img/x
wp-content/uploads/2026/07/x            →  media/2026/07/x
```

平坦化すると出力パスから元ファイルを復元できなくなるため、`Distan_Urls::source_for()` が逆引きを行います。平坦化の規則を変えたら、逆引きも必ず対応させてください。

**本番 URL への置換**は 2 段階です。まず `site_url` 設定による基本のドメイン置換、次に `distan_url_replacements` フィルタで定義された追加ペア（定義順に適用）。後者は `rewrite()` の最後で生成物全体に対して実行されるため、本文・JSON-LD・canonical・OGP のすべてに効きます。CDN 上のアセットや制作環境特有のパスなど、基本置換で届かない箇所の調整用です。

## 構造化データ（JSON-LD）の扱い

Distan は構造化データを**生成しません**。テーマや SEO プラグイン（Yoast、AIOSEO など）が出力した JSON-LD を、`protect_absolute_tags()` で保護しつつ静的化時に保持し、内部の URL を本番 URL に置換して運ぶだけです。生成の責任（型の選択、コンテンツとの整合、SSRF 相当のリスク）を負わないのは意図的な設計判断です。非公開・別ドメインの制作環境でも、**置換を前提にする**ことで本番向けの正しい構造化データを書き出せます。

## 生成完了フック（`distan_after_generate`）

全バッチ完了後、マニフェストとレポートを書き出した直後に一度だけ `do_action( 'distan_after_generate', $manifest, $output_root )` を発火する。**Distan はデプロイを行わない**——このフックは git push / rsync / SFTP / Netlify・Cloudflare Pages の Webhook などを繋ぐための seam。プラグインがデプロイ資格情報やホスト固有の知識を持たずに済むよう、意図的に「知らせるだけ」にしている。本番に WordPress を置かない SSG＋自動デプロイ構成の要。

## デプロイフック（`distan_dispatch`）

`distan_after_generate` が**自動**（生成のたびに必ず発火・状態なし）なのに対し、`distan_dispatch` は**手動の人間ゲート**。生成画面の「デプロイ」ボタン（設定 `enable_dispatch`、既定オフ）を押したときだけ、AJAX ハンドラ `Distan_Ajax::dispatch()` が最後のマニフェストを読んで `do_action( 'distan_dispatch', $manifest, $now )` を発火する。

設計上の要点は「**承認状態を持たない**」こと。「この生成物は OK / NG」という判断を記録すると状態遷移の管理が生まれ、v0.9.16 で引いた「Distan はデプロイをやらない／腐るものを持ち込まない」の線を内側に越える。だから記録するのは `distan_last_dispatch`（最終デプロイ時刻の option 一個）だけ——生成物とも判断とも紐づかない、ただの事実。画面には「最終生成」（`manifest.finished`）と「最終デプロイ」の二行をモノスペースで並べるにとどめ、誰が・何回・デプロイログ一覧、までは踏み込まない（そこは監査ログの領域）。

繋ぐ側の二層構成：`distan_after_generate` で自動的にプレビューへ配信 → 人が目視 →「デプロイ」ボタン → `distan_dispatch` で本番へ昇格。ボタンの作法（nonce `distan_ajax`・capability チェック）は既存の生成 AJAX と同じ `guard()` を共有する。

## Markdown 書き出し（`Distan_Markdown`、選択制）

設定 `export_markdown` が有効なとき、全ページの本文を 1 ファイル `content.md` にまとめて出力ルートに書き出します。Gemini Notebook（旧NotebookLM）等の AI ツールにサイト内容を読ませる用途です。

- 静的 HTML 生成と**同じ巡回**（`render_one` が回収した HTML）から抽出するため、追加のクロールは発生しません
- `<main>` → `<article>` → 本文コンテナ → `<body>` の優先順で本文領域を絞り、header/nav/footer/aside/form/script/style を除去します
- **アーカイブのページネーション（`/page/N/`）は除外**します（個別ページで取得済みの抜粋が重複するため）
- 依存ライブラリを持たない軽量な HTML→Markdown 変換（見出し・段落・リスト・リンクのみ）
- 各ページを 1 件ずつ `content.md` に**追記（ストリーム）**します。全ページ分を溜めてから一括生成しないため、ページ数が膨大でもメモリは一定で、ジョブ（トランジェント）もページ数に比例して膨らみません（1.1.1 のバイナリ・ストリームコピーと同じ思想）。出力は従来とバイト一致
- 再生成のたびに `content.md` は最新化され（各実行の先頭ページで truncate）、機能を無効化すると次回 `clean_output()` の掃除で消えます
- 抽出範囲は `distan_markdown_region` フィルタで調整可能

## リリース手順

1. パッチバンプ（`distan.php` のヘッダと `DISTAN_VERSION` の**両方**）
2. プラグインフォルダ丸ごとを ZIP 化（ファイル名にバージョンを入れる）
3. 動作確認（下記）
4. `git push` して「`xxxx..yyyy  main -> main`」の行を目視確認

## 変更後の確認項目

完全な手順は `TESTING.md` にあります。最低限これだけは毎回実行してください。

```bash
cd wp-content/uploads/distan/dist

# クリーナーが作動したか（最重要・無言の失敗を検出する）
grep -rl 'name="generator"' . ; grep -ril noindex .   # 両方ゼロが正常

# パス平坦化
ls                    # wp-content が無く assets/ media/ があること
find media -type f

# 404 のリンクは絶対 URL であること
grep -o 'href="[^"]*"' 404.html | head

# 表示確認（file:// では ES modules が動かないため）
python3 -m http.server 8000
```

## デバッグ

PHP の致命的エラーは、AJAX レスポンスが JSON でなく HTML になるため
`SyntaxError: Unexpected token '<'` として現れます。原因は `wp-content/debug.log` に出ます。

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

## 既知の制約（仕様であって不具合ではない）

- **画像は HTML に出てきたものだけコピーされる。** メディアライブラリ全体は見ません。`srcset` が付かない小さい画像は 1 サイズだけコピーされます
- **キャッシュ系プラグインが有効だと、古いキャッシュ HTML を取得する可能性がある。** 生成環境には入れないでください
- **ES modules は `file://` で読み込めない**（ブラウザの CORS 仕様。修正不能）
- **現在時刻に依存する出力は生成時点で凍結される**（`date('Y')`、`human_time_diff()`、`rand()`）

## コーディング規約

- プレフィックス: 関数 `distan_` / 定数 `DISTAN_` / クラス `Distan_*`
- テキストドメイン: `distan`
- 管理画面の UI は Alpine.js（同梱、CDN 不使用）。ビルド工程は持ちません
- Alpine より先に自前スクリプトを読み込むため、**Alpine 側の依存として自前スクリプトを宣言**します（逆にすると `x-data` のコンポーネントが未定義になります）
- 翻訳ファイルは配布物に同梱しません（WordPress.org の規約）
