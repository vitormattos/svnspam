# Example line for post-commit
```
REPOS="$1"
REV="$2"

#echo "$*" >> /tmp/svnspam.log

/opt/svnspam/svn_post_commit_hook.php --repository "$REPOS" --revision "$REV" --from developer@test.com --to developer@test.com $*  >> /tmp/svnspam.log 2>&1

```
