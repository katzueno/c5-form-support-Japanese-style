# 標準のメールフォームに確認メール送信機能とCSVを Shift JIS に変換する機能を追加する

concrete5 に標準で同梱されているフォームブロックに、フォームの送信者へも確認メールを送ることができるようにするカスタマイズと、[管理画面] - [レポート] でできる CSV 出力が元々 UTF8 エンコーディングで、MS Excel で開くと文字化けしてしまうので Shift JIS の文字コードに変換して出力するカスタマイズのコードと実装の仕方を紹介します。

## 内容

- Replyto: オプションをつけたメールアドレスに確認メールを送るカスタマイズ
- CSV ダウンロードを、UTF8 ではなくて Shift JIS + CRLF 改行で行うためのカスタマイズ

## 免責 & License

サーバーが UTF8 の設定をしている UNIX 系のサーバーで設定する前提です。この実装による損害をうけても、一切の責任を負わないことに同意した方のみが使用してください。

動作は 5.7.5.3 で確認しました。

## セットアップ方法

[こちらの GitHub](https://github.com/katzueno/c5-form-support-Japanese-style) にあるファイルをダウンロード。

そして concrete5 の `application` フォルダ内の同じ階層に保存してください。

他にファイルをオーバーライドしている人は、間違って、既存の `application` ファイルやフォルダを削除しないようにしてください。

尚 `/application/mail/block_form_submission_user.php` が、ユーザーに送られる確認メールのテンプレートです。英語のままになっているので、日本語にする必要があれば、適宜、変更してください。

## 技術的な説明

### 確認メール

**[確認メール送信実装部分のカスタマイズ例](https://github.com/katzueno/c5-form-support-Japanese-style/commit/3a6542ca656d6c21943d22a4568571e367050dd3)** (GitHub上の Diff)

- メールアドレス入力項目で replyto の設定があるメールアドレスにも確認メールを送る判定を追加
- 管理者にメールを送信した後に、フォーム送信者へ確認メールを送る処理を追加
- フォーム送信者用のメールテンプレートを追加

### CSV を Shift JIS に変換するカスタマイズ例

Windows ユーザーは、Excel で CSV データを開くとき、Shift JIS でないと文字化けしてしまいます。concrete5 は UTF8 をデフォルトで使っているため、Shift JIS に変換する必要があります。

**[CSV の文字コード変換のカスタマイズ例](https://github.com/katzueno/c5-form-support-Japanese-style/commit/8d47f884003925d5b3931dc781c9a6bc36ef6523)** (GitHub上の Diff)

- 管理画面のシングルページの view に、encoding パラメーターを Shift JIS として埋め込み (管理画面から変更不可)
    - 本当は、文字コードを選べるようにしようとも考えましたが、標準のブロックでは元々から POST でデータのやり取りがされていなく、それを実現するには、構造を変えてテストする時間が必要だったので、今回は URL パラメーターとしてShift JIS 指定を埋め込むのみにしました。
- 管理画面のシングルページの controller の form.php は基本的に csv() 関数のカスタマイズのみをカスタマイズしています。
- encoding パラメーターがあった場合に新規の処理を、encoding パラメーターがない場合は従来の処理 (UTF-8) で CSV を出力するよう分岐
- 各パラメーターを、URL で指定があった場合にエンコーディング変換するように設定
- Shift JIS の時だけ、改行コードを UNIX 系の LF から、Windows の CRLF に変換するスクリプトを分岐実行

### コード

実際のコードは GitHub 上で公開しています。

[https://github.com/katzueno/c5-form-support-Japanese-style](https://github.com/katzueno/c5-form-support-Japanese-style)

## 宣伝

コンクリートファイブジャパン株式会社では、企業・団体様の concrete5 サイト制作や制作会社様のプロジェクトのサポートを行っています。また制作会社様向けに「インテグレートパートナー制度」を設け、印刷物では通常は使用禁止している concrete5 のロゴが使えるパートナー制度の運営も行っています。

http://concrete5.co.jp/

以上