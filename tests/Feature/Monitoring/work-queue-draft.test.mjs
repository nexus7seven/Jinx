import test from 'node:test';
import assert from 'node:assert/strict';

function queueCardKey(card){return [card.bot||'pacman',card.case_id,card.notice_id||card.issue_key||''].join(':');}
function rememberReplyDraft(store,input){
 if(!input||!input.dataset.cardKey)return;
 store.set(input.dataset.cardKey,{value:input.value,start:input.selectionStart,end:input.selectionEnd,focused:input.focused===true});
}
function restoreReplyDraft(store,input){
 const draft=store.get(input?.dataset.cardKey);
 if(!draft)return;
 input.value=draft.value;
 if(draft.focused){input.focused=true;input.selectionStart=draft.start??draft.value.length;input.selectionEnd=draft.end??draft.value.length;}
}
function queueInputBusy(store,input){
 if(!input)return false;
 const draft=store.get(input.dataset.cardKey)||{};
 return input.focused===true||!!input.value||!!draft.value;
}
function clearReplyDraft(store,key){if(key)store.delete(key);}

test('queue drafts are per card and survive a simulated refresh',()=>{
 const store=new Map();
 const first={dataset:{cardKey:queueCardKey({bot:'pacman',case_id:335,notice_id:111})},value:'partner earns 12',selectionStart:16,selectionEnd:16,focused:true};
 const second={dataset:{cardKey:queueCardKey({bot:'pacman',case_id:321,notice_id:113})},value:'check HMRC',selectionStart:5,selectionEnd:5,focused:false};
 rememberReplyDraft(store,first);
 rememberReplyDraft(store,second);
 assert.equal(store.size,2);
 const rebuiltFirst={dataset:{cardKey:first.dataset.cardKey},value:'',selectionStart:0,selectionEnd:0,focused:false};
 const rebuiltSecond={dataset:{cardKey:second.dataset.cardKey},value:'',selectionStart:0,selectionEnd:0,focused:false};
 restoreReplyDraft(store,rebuiltFirst);
 restoreReplyDraft(store,rebuiltSecond);
 assert.equal(rebuiltFirst.value,'partner earns 12');
 assert.equal(rebuiltFirst.focused,true);
 assert.equal(rebuiltFirst.selectionStart,16);
 assert.equal(rebuiltSecond.value,'check HMRC');
 assert.equal(rebuiltSecond.focused,false);
 assert.equal(queueInputBusy(store,rebuiltFirst),true);
 clearReplyDraft(store,first.dataset.cardKey);
 assert.equal(store.has(first.dataset.cardKey),false);
 assert.equal(store.has(second.dataset.cardKey),true);
});
