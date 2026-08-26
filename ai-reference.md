# Distan — AI リファレンス

AI エージェントが「Distan で何ができて何ができないか」を素早く把握するための一枚。コードを編集する際の設計ドキュメントは `CLAUDE.md`、人間向けの使い方は `README.md` を参照。

## 一言で

WordPress を制作環境として使い、納品用の静的 HTML を書き出すプラグイン。本番サーバーで動かすものではなく、**成果物（静的 HTML）を作るための道具**。

## できること

- **静的 HTML の書き出し** — 公開中の全ページをループバック HTTP で巡回し、静的 HTML として書き出す。`get_header()` / テンプレート階層 / カスタムフィールド / 条件分岐はすべて通常どおり評価され、その結果が焼き込まれる
- **生成対象** — フロントページ / 公開中の全シングルページ / 投稿アーカイブ＋ページネーション / カテゴリーアーカイブ＋ページネーション / 404 ページ（タグ・著者・日付アーカイブは既定オフ、`distan_taxonomies` で追加可能）
- **URL 変換** — 内部リンクをドキュメント相対（既定）または本番 URL 絶対に変換。ZIP を解凍してダブルクリックで開ける
- **本番 URL への置換** — `site_url` 設定による基本置換 ＋ `distan_url_replacements` フィルタによる追加ペア（複数可・定義順）。本文・JSON-LD・canonical・OGP すべてに適用
- **構造化データの保持** — テーマ/SEO プラグインが出力した JSON-LD を保持し、URL を本番向けに置換して運ぶ（生成はしない）
- **クリーン HTML** — generator / RSD / WLW / REST / oEmbed / 絵文字 / ショートリンク / 投機的読み込み等を除去。`noindex` は常に除去（残す場合は警告）
- **パス平坦化** — テーマ→`assets/`、uploads→`media/`、`wp-content` が消える
- **キャッシュバスティング保持** — アセットのクエリ文字列（`?ver=filemtime` 等）をそのまま保持。ファイル名は不変なので FTP 上書きで更新できる
- **差分レポート＋自動掃除＋差分 MD＋ZIP** — 再生成のたびに追加/変更/リンク切れを報告。出力先の掃除は自動、削除は報告のみ（本番には触れない）
- **列挙の由来（provenance）** — 生成した各ページに由来（投稿とその ID／タクソノミー・ターム／アーカイブのページ番号など）を記録。差分レポートはファイルパスだけでなく「投稿タイトル [投稿 #123]」の形で変更を名指す。増分スキップはしない（毎回すべて再生成する）
- **大きいファイルの検出と警告** — 画像・PDF・フォント・動画などバイナリはストリームコピー（メモリに全体を載せない）。一定サイズ超（既定 10MB、`distan_large_file_threshold` フィルタ）はレポートに一覧表示。コピーはするので手作業は不要（納品サイズや CDN 検討の目安）。CSS だけは url() 書き換えのためメモリに読む
- **Markdown 書き出し（選択制）** — 全ページ本文を 1 ファイル `content.md` にまとめる。Gemini Notebook（旧NotebookLM）等の AI ツール向け。ページネーションは除外。URL は公開 URL に置換（開発用に `content.local.md` も選択可）
- **サイトマップ書き出し（選択制）** — 生成したページから `sitemap.xml` を作る（Google Search Console 対応の標準形式）。URL は公開 URL。著者・日付アーカイブは収集対象外なので `?author=1` 等の ID は載らない。`/private/`（スラッグ以下）や `draft`（語を含む）で除外指定できる（設定 または `distan_sitemap_exclude` フィルタ）
- **robots.txt 書き出し（選択制）** — 最小構成（`Allow: /`）。サイトマップが有効なときは `Sitemap:` 行も記載。既にサーバーに robots.txt がある場合はオフにする
- **リンク監査** — 内部リンクの切れを検出して報告
- **カスタム URL ソース** — `distan_sources` フィルタで、列挙が発見できない URL（プラグイン生成の経路・仮想ページ）を第一級で追加できる。`Distan_Collector::make_item()` で作り、重複排除の前に合流するので、追加 URL もカウント・差分・重複排除の対象になる（生の最終手段として `distan_collect` は残置）
- **URL パラメータでの出し分けに対応（選択制）** — `distan_variant_keys` フィルタで「表示を分けるクエリキー」（例 `tab`, `lang`）を宣言すると、`/slug/?tab=a` と `?tab=b` がそれぞれ独立した静的ファイル（`/slug/tab-a/index.html` 等）に書き出され、内部リンクもその畳み込み先へ書き換わる。素の静的ホストはパスでしか引けないため、クエリをパスに畳み込む方式。どの値が存在するかは `distan_sources` で宣言する（列挙では発見できないため）。宣言したキー以外のクエリ（`utm_*`・`fbclid` 等）は従来どおり無視するので列挙は膨張しない。畳み込み形式は `distan_query_variant_segment` フィルタで変更可（既定は `key-value`、複数キーはキー順に `_` 連結）。既定オフ＝キーを宣言するまで挙動は不変
- **コアサイトマップ突き合わせ** — 生成後、WordPress コアの `wp-sitemap` をプロセス内で読み（HTTP なし・クロールなし）、コアが挙げているのに未生成の URL をレポートに一覧して網羅の穴を可視化する。`distan_use_core_sitemap` で、それらを補助ソースとして列挙に合流も可能（既定オフ。コアサイトマップは noindex を尊重し無効化もされうるため、置換ではなく補助扱い）
- **差分エクスポート（変更分のみ）** — 前回生成からの追加・変更ファイルだけを ZIP にまとめる（`Distan_Report::build_diff_zip`）。各ファイルは本番と同じ相対パスで入るので、解凍してそのまま上書きできる（全体を上げ直さない）。前回はあったが今回未生成のファイルは同梱の `DELETE.txt` に一覧（削除指示）、概要は `distan-diff.md`。変更検知はページ HTML の内容ハッシュで行い、パスが同じでも中身が変わったページを「変更」として検出する（アセットは従来どおり追加/削除で追跡）。アップグレード直後はハッシュを記録するだけで全ページを誤って「変更」扱いしない（検知は次回から）。全体 ZIP は初回納品・節目用として残置。生成画面の「差分ZIP」設定（既定オン）で表示を切替できるが、検知自体は常に走るので後からオンにしてもその時点から正しく差分が出る
- **差分基準の選択** — `distan_manifest_source` フィルタで差分の基準（前回状態）の置き場所を選ぶ。既定 `db`（WordPress オプション、生成＝デプロイが同一環境の場合に最適）／ `output`（成果物ツリー内の携行 manifest `.distan/manifest.json`）。`output` は生成環境とデプロイ先が分かれる場合（ローカルや CI で生成 → 別サーバーへ納品）に、差分の基準を成果物と一緒に運べる。同ディレクトリに deny 用 `.htaccess` を書いて公開を防ぐ（Apache。他サーバーは同等の location ルールが別途必要）。既定 `db` は従来どおりで、宣言するまで挙動は不変
- **テンプレート書き出し** — 生成済みページを 1 枚選ぶと、そのページ＋そのページが実際に参照するアセットだけ（CSS・JS・フォント・画像。スタイルシートの `url()`・`@import` も再帰的に辿る）を本番と同じ相対パスで ZIP 化（`Distan_Report::build_template_zip`）。共通ヘッダー・フッターに沿った特設ページ制作を外部に委ねる際の「雛形」として渡す用途。全ページ分のアセットは同梱しない（必要なのは共通 chrome だけ）。ナビ等の遷移先ページはこの 1 枚納品では欠落する前提。ZIP ルートに制作者向け `README.md`（head/header/footer は不可侵・本文だけ差し替え・相対パス維持）を同梱。本文領域の機械的な切り出しは行わない（構造マップで露呈した「沈黙して壊れる」失敗を避け、ページを丸ごと渡して差し替え箇所を指示する）。参照解決は完成済み出力を読むので状態が確定しており、動的読み込みの取りこぼしはプレビューで見た目が崩れて即座に気づける。生成画面の「テンプレート書き出し」設定（既定オン）で表示を切替
- **生成完了フック** — `distan_after_generate`（アクション）で、生成後に任意のデプロイ処理（git push / rsync / Webhook 等）を繋げる。Distan 自体はデプロイしない・認証情報を持たない
- **デプロイフック** — `distan_dispatch`（アクション）は、生成物を目視確認したあと手動の「デプロイ」ボタンを押したときだけ発火する。`distan_after_generate` が自動（生成のたびに必ず発火）なのに対し、`distan_dispatch` は人間のゲート。プレビュー配信は前者、本番へのデプロイは後者、と二層に分けられる。承認状態は持たず、最終デプロイ時刻（`distan_last_dispatch`）だけを記録する。ボタンは既定オフ、設定で有効化

## できないこと / やらないこと

- **本番で動的処理をしない** — 生成物は静的 HTML のみ。フォーム送信・検索・コメント等の動的機能は本番で動かない（外部サービスや別途の隔離エンドポイントで対応する設計）
- **構造化データを生成しない** — JSON-LD の生成は SEO プラグインの領分。Distan は保持と URL 置換のみ
- **リアルタイム更新をしない** — RSS 等の外部データは生成時点のスナップショットとして焼き込まれる。最新化は再生成で行う（ニアリアルタイム）
- **メディアライブラリ全体はコピーしない** — HTML に出てきた画像だけコピー。`srcset` の無い小画像は 1 サイズのみ
- **ES modules は `file://` で動かない** — ブラウザの CORS 仕様。クラシックテーマ（module 不使用）なら回避可能
- **現在時刻依存の出力は凍結される** — `date('Y')` / `human_time_diff()` / `rand()` 等は生成時点で固定
- **出力先は固定** — `wp-content/uploads/distan/dist`。変更不可（セキュリティ上の理由）
- **DB 直接 SQL / exec / 外部バイナリ / Composer 依存 / 外部 API を使わない** — 唯一の環境依存はループバック通信

## 主なフィルタ

`distan_taxonomies` `distan_post_types` `distan_collect` `distan_archive_max_pages` `distan_term_max_pages` `distan_404_probe` `distan_flatten_theme` `distan_uploads_dir` `distan_clean_html` `distan_clean_output` `distan_remove_global_styles` `distan_robots` `distan_head_actions` `distan_dequeue_handles` `distan_blocked_extensions` `distan_capability` `distan_url_replacements` `distan_markdown_region` `distan_sitemap_exclude` `distan_sitemap_entries` `distan_robots_lines` `distan_large_file_threshold` `distan_sources` `distan_use_core_sitemap` `distan_sitemap_audit_max_pages` `distan_variant_keys` `distan_query_variant_segment` `distan_manifest_source`

## 生成キューのエントリ形式

`distan_sources`（登録）と `distan_collect`（最終手段）で扱うエントリの形。フィルタ境界を越えるので実質的な公開 API。

- 形: `array{ url, path, label, type, source }`。`Distan_Collector::make_item( $url, $label, $source )` で作る（`path` は `url` から導出されるので手で埋めない）
- `source.kind` の種類: `front` / `blog_home`（id）/ `blog_archive`（page）/ `post`（id, post_type）/ `term`（taxonomy, id, page）/ `not_found` / `extra`（origin）
- 追加した URL は重複排除の前に合流し、カウント・差分・dedup の対象になる（`distan_collect` の生編集も直後にもう一度 dedup される）

## 前提・注意

- キャッシュ系プラグインが有効だと古いキャッシュ HTML を取得する恐れ。生成環境には入れない
- 生成は毎回フル生成（差分生成ではない）。差分は「レポート」で見せる
- 配布は 2 種類: GitHub 版（CLAUDE.md 等の開発ファイル同梱）と WordPress.org 版（除外、readme.txt 同梱）

## デザイン

管理画面のビジュアルデザイン（配色・タイポグラフィ・世界観「Distan Dispatch＝出荷伝票」）は `DESIGN.md`（Google の design.md 形式）に定義。UI を触るときはこれに従う。
